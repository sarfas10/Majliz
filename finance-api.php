<?php
// finance-api.php - API for financial transactions (with donor search restricted to logged-in mahal)
// Features: save/get/delete/download/receipt, donor search, member adjustments, payment_mode
// Includes: get_bulk_members, get_balance_by_mode, get_staff_for_salary, save_bulk, bulk_receipt, sahakari support in bulk

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
header('Content-Type: application/json; charset=utf-8');

// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

/* --------------------------- Schema ensure --------------------------- */
// NOTE: members and sahakari_members tables are created and managed by 
// addmember.php and add_sahakari.php respectively.
// We only ensure finance-specific columns exist here.

// transactions table (includes donor columns + payment_mode + other_expense_detail)
$createTableSQL = "CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    type ENUM('INCOME', 'EXPENSE') NOT NULL,
    category VARCHAR(255) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    description TEXT,
    other_expense_detail VARCHAR(255) NULL,
    donor_member_id INT NULL,
    donor_details VARCHAR(255) NULL,
    payment_mode VARCHAR(20) NOT NULL DEFAULT 'CASH',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    staff_id INT NULL,
    receipt_no VARCHAR(20) NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_type (type),
    INDEX idx_category (category),
    INDEX idx_user_date (user_id, transaction_date),
    INDEX idx_donor_member_id (donor_member_id),
    INDEX idx_staff_id (staff_id),
    FOREIGN KEY (user_id) REFERENCES register(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($createTableSQL)) {
    error_log("Could not create transactions table: " . $conn->error);
}

// Ensure columns exist for existing DBs (MySQL 8+)
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(20) NOT NULL DEFAULT 'CASH'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS other_expense_detail VARCHAR(255) NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS staff_id INT NULL");
$conn->query("ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_staff_id (staff_id)");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS receipt_no VARCHAR(20) NULL");

// Linkage columns for Asset Management
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS asset_id INT NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS asset_booking_id INT NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS asset_maintenance_id INT NULL");
$conn->query("ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_asset_id (asset_id)");
$conn->query("ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_asset_booking_id (asset_booking_id)");
$conn->query("ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_asset_maintenance_id (asset_maintenance_id)");

// Ensure members columns exist (only if table already exists from addmember.php)
$checkMembers = $conn->query("SHOW TABLES LIKE 'members'");
if ($checkMembers && $checkMembers->num_rows > 0) {
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS total_donations_received DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS total_due DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_donation_due VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_fee_adv DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE members ADD COLUMN IF NOT EXISTS bulk_print_enabled TINYINT(1) NOT NULL DEFAULT 0");
    // Note: member_number column and its composite unique index are managed by addmember.php
    $checkMembers->close();
}

// Ensure family_members has required columns (only if table exists)
$checkFamily = $conn->query("SHOW TABLES LIKE 'family_members'");
if ($checkFamily && $checkFamily->num_rows > 0) {
    $conn->query("ALTER TABLE family_members ADD COLUMN IF NOT EXISTS relationship VARCHAR(100) NULL");
    $conn->query("ALTER TABLE family_members ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL");
    $conn->query("ALTER TABLE family_members ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL");
    $checkFamily->close();
}

// Ensure sahakari_members columns exist (only if table already exists from add_sahakari.php)
$hasSahakariForSchema = false;
if ($rt = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
    $hasSahakariForSchema = $rt->num_rows > 0;
    $rt->close();
}
if ($hasSahakariForSchema) {
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS total_donations_received DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS total_due DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS monthly_donation_due VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS monthly_fee_adv DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    $conn->query("ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS bulk_print_enabled TINYINT(1) NOT NULL DEFAULT 0");
    // Note: member_number column and its composite unique index are managed by add_sahakari.php
}

// Ensure staff table exists and has salary tracking fields
$createStaffTableSQL = "CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    designation VARCHAR(100),
    fixed_salary DECIMAL(12,2) DEFAULT 0.00,
    salary_payment_status VARCHAR(20) DEFAULT 'unpaid',
    last_salary_paid_date DATE NULL,
    last_salary_paid_amount DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mahal_id (mahal_id),
    FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$conn->query($createStaffTableSQL)) {
    error_log("Could not create staff table: " . $conn->error);
}

$conn->query("ALTER TABLE staff ADD COLUMN IF NOT EXISTS salary_payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
$conn->query("ALTER TABLE staff ADD COLUMN IF NOT EXISTS last_salary_paid_date DATE NULL");
$conn->query("ALTER TABLE staff ADD COLUMN IF NOT EXISTS last_salary_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00");
$conn->query("ALTER TABLE staff ADD COLUMN IF NOT EXISTS fixed_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00");

/* ------------------- Ensure staff_payments table exists ------------------- */
$createStaffPayments = "
CREATE TABLE IF NOT EXISTS staff_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    staff_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_mode VARCHAR(50) DEFAULT 'CASH',
    description VARCHAR(255) DEFAULT NULL,
    INDEX (mahal_id),
    INDEX (staff_id),
    INDEX (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$conn->query($createStaffPayments)) {
    error_log('Could not create staff_payments table: ' . $conn->error);
}

/* --------------------------- Helpers --------------------------- */
function json_input()
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
    }
    return $decoded;
}

/**
 * Determine which table a donor id belongs to: 'members', 'sahakari_members' or null.
 */
function resolve_donor_table(mysqli $conn, int $donor_member_id, int $mahal_id): ?string
{
    // Check members
    if ($stmt = $conn->prepare("SELECT id FROM members WHERE id = ? AND mahal_id = ?")) {
        $stmt->bind_param("ii", $donor_member_id, $mahal_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->fetch_assoc()) {
            $stmt->close();
            return 'members';
        }
        $stmt->close();
    }

    // Check if sahakari_members table exists
    $hasSah = false;
    if ($rt = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
        $hasSah = $rt->num_rows > 0;
        $rt->close();
    }
    if ($hasSah) {
        if ($stmt2 = $conn->prepare("SELECT id FROM sahakari_members WHERE id = ? AND mahal_id = ?")) {
            $stmt2->bind_param("ii", $donor_member_id, $mahal_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2 && $res2->fetch_assoc()) {
                $stmt2->close();
                return 'sahakari_members';
            }
            $stmt2->close();
        }
    }

    return null;
}

$action = $_GET['action'] ?? '';

/* ------------------- EXPORT transactions to Excel ------------------- */
if ($action === 'export_excel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $type_filter = $_GET['type_filter'] ?? 'all';
    $category_filter = $_GET['category_filter'] ?? 'all';
    $payment_mode_filter = $_GET['payment_mode_filter'] ?? 'all';

    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }

    // Build export URL with filters
    $export_url = "export_finance_excel.php?";
    $params = [];
    $params[] = "user_id=" . urlencode($user_id);
    $params[] = "date_from=" . urlencode($date_from);
    $params[] = "date_to=" . urlencode($date_to);
    $params[] = "type_filter=" . urlencode($type_filter);
    $params[] = "category_filter=" . urlencode($category_filter);
    $params[] = "payment_mode_filter=" . urlencode($payment_mode_filter);

    $export_url .= implode('&', $params);

    echo json_encode([
        'success' => true,
        'export_url' => $export_url,
        'message' => 'Export ready'
    ]);
    exit();
}

/* ------------------- Member search (restricted to this mahal) ------------------- */
if ($action === 'search_members' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $q_raw = trim($_GET['q'] ?? '');
    $limit = 30;
    $mahal_id = (int) $_SESSION['user_id'];
    $results = [];

    if ($q_raw === '') {
        echo json_encode(['success' => true, 'results' => []]);
        exit();
    }

    $isNumeric = ctype_digit($q_raw);
    $like = '%' . $q_raw . '%';

    /* -------- 0) Helper: check if sahakari_members table exists -------- */
    $hasSahakari = false;
    if ($resTmp = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
        $hasSahakari = $resTmp->num_rows > 0;
        $resTmp->close();
    }

    /* -------- 1) Exact numeric search on member_number (members + sahakari_members) -------- */
    if ($isNumeric) {
        // Exact in members
        $exact_members_sql = "
            SELECT 
                m.id AS head_id,
                m.head_name AS person_name,
                m.address,
                m.phone,
                COALESCE(m.total_due, 0) AS total_due,
                COALESCE(m.monthly_fee_adv, 0) AS monthly_fee_adv,
                COALESCE(m.monthly_fee, 0) AS monthly_fee,
                m.member_number
            FROM members m
            WHERE m.mahal_id = ?
              AND m.member_number = ?
            ORDER BY m.head_name ASC
            LIMIT ?
        ";
        if ($heads_stmt = $conn->prepare($exact_members_sql)) {
            $heads_stmt->bind_param("isi", $mahal_id, $q_raw, $limit);
            $heads_stmt->execute();
            $heads_res = $heads_stmt->get_result();
            while ($r = $heads_res->fetch_assoc()) {
                $results[] = [
                    'person_type' => 'HEAD',
                    'person_id' => null, // For heads, person_id is null
                    'head_id' => (int) $r['head_id'],
                    'person_name' => $r['person_name'],
                    'display' => $r['person_name'] . ' (Head)',
                    'address' => $r['address'] ?? '',
                    'phone' => $r['phone'] ?? '',
                    'total_due' => (float) $r['total_due'],
                    'monthly_fee_adv' => (float) $r['monthly_fee_adv'],
                    'monthly_fee' => (float) $r['monthly_fee'],
                    'member_number' => $r['member_number'] ?? null,
                ];
            }
            $heads_stmt->close();
        }

        // Exact in sahakari_members
        if ($hasSahakari) {
            $exact_sah_sql = "
                SELECT 
                    s.id AS head_id,
                    s.head_name AS person_name,
                    s.address,
                    s.phone,
                    COALESCE(s.total_due, 0) AS total_due,
                    COALESCE(s.monthly_fee_adv, 0) AS monthly_fee_adv,
                    COALESCE(s.monthly_fee, 0) AS monthly_fee,
                    s.member_number
                FROM sahakari_members s
                WHERE s.mahal_id = ?
                  AND s.member_number = ?
                ORDER BY s.head_name ASC
                LIMIT ?
            ";
            if ($s_stmt = $conn->prepare($exact_sah_sql)) {
                $s_stmt->bind_param("isi", $mahal_id, $q_raw, $limit);
                $s_stmt->execute();
                $s_res = $s_stmt->get_result();
                while ($r = $s_res->fetch_assoc()) {
                    $results[] = [
                        'person_type' => 'SAHAKARI',
                        'person_id' => null,
                        'head_id' => (int) $r['head_id'],
                        'person_name' => $r['person_name'],
                        'display' => $r['person_name'] . ' (Sahakari)',
                        'address' => $r['address'] ?? '',
                        'phone' => $r['phone'] ?? '',
                        'total_due' => (float) $r['total_due'],
                        'monthly_fee_adv' => (float) $r['monthly_fee_adv'],
                        'monthly_fee' => (float) $r['monthly_fee'],
                        'member_number' => $r['member_number'] ?? null,
                    ];
                }
                $s_stmt->close();
            }
        }

        // If we found any exact matches (either members or sahakari_members), return only those.
        if (!empty($results)) {
            echo json_encode(['success' => true, 'results' => $results]);
            exit();
        }
        // else fall through to flexible LIKE search
    }

    /* -------- 2) Flexible LIKE search: members (heads) -------- */
    $paramsLike = '%' . $q_raw . '%';

    $heads_sql = "
        SELECT 
            m.id AS head_id, 
            m.head_name AS person_name,
            m.address,
            m.phone,
            COALESCE(m.total_due, 0) AS total_due,
            COALESCE(m.monthly_fee_adv, 0) AS monthly_fee_adv,
            COALESCE(m.monthly_fee, 0) AS monthly_fee,
            m.member_number
        FROM members m
        WHERE m.mahal_id = ?
          AND (
                m.head_name LIKE ?
             OR (m.member_number IS NOT NULL AND m.member_number LIKE ?)
             OR (m.phone IS NOT NULL AND m.phone LIKE ?)
          )
        ORDER BY m.head_name ASC
        LIMIT ?
    ";
    if ($heads_stmt = $conn->prepare($heads_sql)) {
        $heads_stmt->bind_param("issii", $mahal_id, $paramsLike, $paramsLike, $paramsLike, $limit);
        $heads_stmt->execute();
        $heads_res = $heads_stmt->get_result();
        while ($r = $heads_res->fetch_assoc()) {
            $results[] = [
                'person_type' => 'HEAD',
                'person_id' => null, // For heads, person_id is null
                'head_id' => (int) $r['head_id'],
                'person_name' => $r['person_name'],
                'display' => $r['person_name'] . ' (Head)',
                'address' => $r['address'] ?? '',
                'phone' => $r['phone'] ?? '',
                'total_due' => (float) $r['total_due'],
                'monthly_fee_adv' => (float) $r['monthly_fee_adv'],
                'monthly_fee' => (float) $r['monthly_fee'],
                'member_number' => $r['member_number'] ?? null,
            ];
        }
        $heads_stmt->close();
    }

    /* -------- 3) Flexible LIKE search: family_members -------- */
    // First check if family_members table exists
    $hasFamilyMembers = false;
    if ($resFam = $conn->query("SHOW TABLES LIKE 'family_members'")) {
        $hasFamilyMembers = $resFam->num_rows > 0;
        $resFam->close();
    }

    if ($hasFamilyMembers) {
        $fm_sql = "
            SELECT 
                fm.id AS fm_id, 
                fm.name AS fm_name, 
                fm.relationship,
                m.id AS head_id, 
                m.head_name,
                m.address,
                m.phone,
                COALESCE(m.total_due, 0) AS total_due,
                COALESCE(m.monthly_fee_adv, 0) AS monthly_fee_adv,
                COALESCE(m.monthly_fee, 0) AS monthly_fee,
                m.member_number
            FROM family_members fm
            JOIN members m ON fm.member_id = m.id
            WHERE m.mahal_id = ?
              AND (
                    fm.name LIKE ?
                 OR m.head_name LIKE ?
                 OR (m.member_number IS NOT NULL AND m.member_number LIKE ?)
                 OR (m.phone IS NOT NULL AND m.phone LIKE ?)
              )
            ORDER BY fm.name ASC
            LIMIT ?
        ";
        if ($fm_stmt = $conn->prepare($fm_sql)) {
            $fm_stmt->bind_param("issssi", $mahal_id, $paramsLike, $paramsLike, $paramsLike, $paramsLike, $limit);
            $fm_stmt->execute();
            $fm_res = $fm_stmt->get_result();
            while ($r = $fm_res->fetch_assoc()) {
                $display = $r['fm_name'] . ' (' . $r['relationship'] . ' of ' . $r['head_name'] . ')';
                $results[] = [
                    'person_type' => 'FAMILY',
                    'person_id' => (int) $r['fm_id'], // Family member's own ID
                    'head_id' => (int) $r['head_id'], // Head's ID
                    'person_name' => $r['fm_name'],
                    'display' => $display,
                    'address' => $r['address'] ?? '',
                    'phone' => $r['phone'] ?? '',
                    'total_due' => (float) $r['total_due'],
                    'monthly_fee_adv' => (float) $r['monthly_fee_adv'],
                    'monthly_fee' => (float) $r['monthly_fee'],
                    'member_number' => $r['member_number'] ?? null,
                ];
            }
            $fm_stmt->close();
        }
    }

    /* -------- 4) Flexible LIKE search: sahakari_members -------- */
    if ($hasSahakari) {
        $sah_sql = "
            SELECT 
                s.id AS head_id,
                s.head_name AS person_name,
                s.address,
                s.phone,
                COALESCE(s.total_due, 0) AS total_due,
                COALESCE(s.monthly_fee_adv, 0) AS monthly_fee_adv,
                COALESCE(s.monthly_fee, 0) AS monthly_fee,
                s.member_number
            FROM sahakari_members s
            WHERE s.mahal_id = ?
              AND (
                    s.head_name LIKE ?
                 OR (s.member_number IS NOT NULL AND CAST(s.member_number AS CHAR) LIKE ?)
                 OR (s.phone IS NOT NULL AND s.phone LIKE ?)
              )
            ORDER BY s.head_name ASC
            LIMIT ?
        ";
        if ($s_stmt = $conn->prepare($sah_sql)) {
            $s_stmt->bind_param("isssi", $mahal_id, $paramsLike, $paramsLike, $paramsLike, $limit);
            $s_stmt->execute();
            $s_res = $s_stmt->get_result();
            while ($r = $s_res->fetch_assoc()) {
                $results[] = [
                    'person_type' => 'SAHAKARI',
                    'person_id' => null,
                    'head_id' => (int) $r['head_id'],
                    'person_name' => $r['person_name'],
                    'display' => $r['person_name'] . ' (Sahakari)',
                    'address' => $r['address'] ?? '',
                    'phone' => $r['phone'] ?? '',
                    'total_due' => (float) $r['total_due'],
                    'monthly_fee_adv' => (float) $r['monthly_fee_adv'],
                    'monthly_fee' => (float) $r['monthly_fee'],
                    'member_number' => $r['member_number'] ?? null,
                ];
            }
            $s_stmt->close();
        }
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit();
}

/* ------------------- GET transactions ------------------- */
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);

    // 1. Determine "Launch Date" (Date of first MANUAL transaction OR first asset transaction)
    $cutoff_date = null;

    // Get the earliest date from EITHER manual transactions OR asset-related transactions
    $min_sql = "SELECT MIN(transaction_date) FROM transactions WHERE user_id = ?";
    if ($min_stmt = $conn->prepare($min_sql)) {
        $min_stmt->bind_param("i", $user_id);
        $min_stmt->execute();
        $min_stmt->bind_result($min_date);
        if ($min_stmt->fetch() && $min_date) {
            $cutoff_date = $min_date;
        }
        $min_stmt->close();
    }

    // 2. Fetch Transactions (With Filter)
    $where_clause = "WHERE t.user_id = ?";

    // If we have a cutoff date, filter transactions to be on or after that date
    if ($cutoff_date) {
        $where_clause .= " AND (
            t.transaction_date >= '$cutoff_date'
            OR 
            (t.asset_booking_id IS NOT NULL AND b.end_date >= '$cutoff_date')
        )";
    }

    $sql = "SELECT 
            t.id, t.user_id, t.transaction_date as date, t.type, t.category, 
            t.amount, t.description, t.other_expense_detail, t.donor_member_id, 
            t.donor_details, t.payment_mode, t.staff_id, t.receipt_no, 
            t.asset_id, t.asset_booking_id, t.asset_maintenance_id, t.created_at,
            COALESCE(m.member_number, s.member_number) as member_number
        FROM transactions t
        LEFT JOIN asset_bookings b ON t.asset_booking_id = b.id
        LEFT JOIN members m ON t.donor_member_id = m.id AND m.mahal_id = t.user_id
        LEFT JOIN sahakari_members s ON t.donor_member_id = s.id AND s.mahal_id = t.user_id
        $where_clause
        ORDER BY t.transaction_date DESC, t.created_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $transactions = [];
    while ($row = $res->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'transactions' => $transactions]);
    exit();
}

/* ------------------- GET approved bookings ------------------- */
if ($action === 'get_approved_bookings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);

    // Fetch approved bookings that are NOT already fully linked? 
    // For now, just simplly fetch all APPROVED bookings to let user select.
    $sql = "SELECT b.id, b.asset_id, b.booked_by, b.booking_amount, b.start_date, b.end_date, a.name as asset_name 
            FROM asset_bookings b
            JOIN assets a ON b.asset_id = a.id
            WHERE b.mahal_id = ? AND b.status = 'approved'
            ORDER BY b.created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $bookings = [];
        while ($row = $res->fetch_assoc()) {
            $bookings[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'bookings' => $bookings]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error']);
    }
    exit();
}

/* ------------------- GET taxable assets ------------------- */
if ($action === 'get_taxable_assets' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);

    // Fetch assets that have taxable_amount > 0 and are active
    $sql = "SELECT a.id, a.asset_code, a.name, a.taxable_amount, ac.category_name 
            FROM assets a
            JOIN asset_categories ac ON a.category_id = ac.id
            WHERE a.mahal_id = ? AND a.status = 'active' AND a.taxable_amount > 0
            ORDER BY a.name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $assets = [];
        while ($row = $res->fetch_assoc()) {
            $assets[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'assets' => $assets]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error']);
    }
    exit();
}

/* ------------------- GET rental assets (with category) ------------------- */
if ($action === 'get_rental_assets' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);

    // Fetch active rental assets with category name
    $sql = "SELECT a.id, a.asset_code, a.name, ac.category_name 
            FROM assets a
            JOIN asset_categories ac ON a.category_id = ac.id
            WHERE a.mahal_id = ? AND a.status = 'active' AND a.rental_status = 'rental'
            ORDER BY a.name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $assets = [];
        while ($row = $res->fetch_assoc()) {
            $assets[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'assets' => $assets]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    }
    exit();
}

/* ------------------- SAVE transaction ------------------- */ elseif ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    if (isset($input['error'])) {
        echo json_encode(['success' => false, 'message' => $input['error']]);
        exit();
    }

    $user_id = intval($input['user_id'] ?? 0);
    $date = $input['date'] ?? '';
    $type = strtoupper(trim($input['type'] ?? ''));
    $category = strtoupper(trim($input['category'] ?? ''));
    $amount = floatval($input['amount'] ?? 0);
    $description = $input['description'] ?? null;

    // donor info: expecting donor_member_id (head id) and donor_details (person name)
    $donor_member_id = isset($input['donor_member_id']) && $input['donor_member_id'] !== '' ? intval($input['donor_member_id']) : null;
    $donor_details = isset($input['donor_details']) ? trim($input['donor_details']) : null;

    // payment_mode (CASH/G PAY), default CASH
    $payment_mode = strtoupper(trim($input['payment_mode'] ?? 'CASH'));
    if (!in_array($payment_mode, ['CASH', 'G PAY'], true)) {
        $payment_mode = 'CASH';
    }

    // other_expense_detail (only meaningful when EXPENSE + OTHER EXPENSES)
    $other_expense_detail = isset($input['other_expense_detail']) ? trim($input['other_expense_detail']) : null;

    // staff_id (only relevant for EXPENSE + SALARY)
    $staff_id = isset($input['staff_id']) && $input['staff_id'] !== '' ? intval($input['staff_id']) : null;

    // Asset linking fields
    $asset_id = isset($input['asset_id']) && $input['asset_id'] !== '' ? intval($input['asset_id']) : null;
    $asset_booking_id = isset($input['asset_booking_id']) && $input['asset_booking_id'] !== '' ? intval($input['asset_booking_id']) : null;
    $asset_maintenance_id = isset($input['asset_maintenance_id']) && $input['asset_maintenance_id'] !== '' ? intval($input['asset_maintenance_id']) : null;

    // validation
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }
    if (!$date) {
        echo json_encode(['success' => false, 'message' => 'Missing date']);
        exit();
    }
    if (!$type) {
        echo json_encode(['success' => false, 'message' => 'Missing type']);
        exit();
    }
    if (!$category) {
        echo json_encode(['success' => false, 'message' => 'Missing category']);
        exit();
    }
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid amount']);
        exit();
    }

    // If category is in the needs-detail list and type is EXPENSE, require other_expense_detail
    $detailCategories = [
        'OFFICE EXPENSE',
        'PURCHASE',
        'BUILDING EXPENSE',
        'STATIONARY EXPENSE',
        'ELECTRICITY BILL',
        'USTHAD FOOD',
        'CLEANING EXPENSE',
        'CHERIYA PERUNAL',
        'BALI PERUNAL',
        'NABIDHINAM',
        'OTHER EXPENSES'
    ];

    if ($type === 'EXPENSE' && in_array(strtoupper($category), $detailCategories)) {
        if (!$other_expense_detail) {
            echo json_encode(['success' => false, 'message' => 'Please provide details for ' . $category]);
            exit();
        }
    }

    // If donor_member_id is provided, enforce that donor is in this mahal (members or sahakari_members)
    $donor_table = null;
    if ($donor_member_id !== null) {
        $mahal_id = (int) $_SESSION['user_id'];
        $donor_table = resolve_donor_table($conn, $donor_member_id, $mahal_id);
        if ($donor_table === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid donor for this mahal']);
            exit();
        }
    }

    // If staff_id is provided for salary payments, verify staff belongs to this mahal
    if ($staff_id !== null) {
        $chkStaff = $conn->prepare("SELECT id FROM staff WHERE id = ? AND mahal_id = ?");
        if ($chkStaff) {
            $chkStaff->bind_param("ii", $staff_id, $user_id);
            $chkStaff->execute();
            $sr = $chkStaff->get_result()->fetch_assoc();
            $chkStaff->close();
            if (!$sr) {
                echo json_encode(['success' => false, 'message' => 'Invalid staff selected for this mahal']);
                exit();
            }
        }
    }

    // Insert + updates inside a DB transaction
    $conn->begin_transaction();

    try {
        // Per-mahal, per-year receipt counter based on transaction_date
        $transYear = (int) date('Y', strtotime($date));
        $receiptCounter = 0;
        $rcStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
        if ($rcStmt) {
            $rcStmt->bind_param("ii", $user_id, $transYear);
            $rcStmt->execute();
            $rcRes = $rcStmt->get_result();
            if ($rcRow = $rcRes->fetch_assoc()) {
                $receiptCounter = (int) $rcRow['cnt'];
            }
            $rcStmt->close();
        }
        $nextReceiptNum = $receiptCounter + 1;
        // R = Receipt (income), V = Voucher (expense)
        $receiptPrefix = ($type === 'EXPENSE') ? 'V' : 'R';
        $receiptNumber = $receiptPrefix . $nextReceiptNum . '/' . $transYear;

        $sql = "INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, other_expense_detail, donor_member_id, donor_details, staff_id, payment_mode, asset_id, asset_booking_id, asset_maintenance_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $u = $user_id;
        $d_date = $date;
        $d_type = $type;
        $d_cat = $category;
        $d_amount = $amount;
        $d_desc = $description;
        $d_other = $other_expense_detail;
        $d_donor_id = $donor_member_id;
        $d_donor_details = $donor_details;
        $d_staff_id = $staff_id;
        $d_payment_mode = $payment_mode;

        $stmt->bind_param(
            "isssdssisssiii",
            $u,
            $d_date,
            $d_type,
            $d_cat,
            $d_amount,
            $d_desc,
            $d_other,
            $d_donor_id,
            $d_donor_details,
            $d_staff_id,
            $d_payment_mode,
            $asset_id,
            $asset_booking_id,
            $asset_maintenance_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to save transaction: " . $stmt->error);
        }

        $insertId = $conn->insert_id;
        $stmt->close();

        // Update receipt_no for this transaction
        $updReceipt = $conn->prepare("UPDATE transactions SET receipt_no = ? WHERE id = ?");
        if ($updReceipt) {
            $updReceipt->bind_param("si", $receiptNumber, $insertId);
            if (!$updReceipt->execute()) {
                $updReceipt->close();
                throw new Exception("Failed to update receipt number: " . $updReceipt->error);
            }
            $updReceipt->close();
        } else {
            throw new Exception("Failed to prepare receipt number update: " . $conn->error);
        }

        // Unified Business rule for MONTHLY FEE + ADVANCE (for members AND sahakari_members):
        // - If there is due, payment first reduces total_due.
        // - Any remaining amount is added to monthly_fee_adv.
        // - If due becomes 0, mark monthly_donation_due='cleared'.
        if ($type === 'INCOME' && in_array(strtoupper($category), ['MONTHLY FEE', 'MONTHLY FEE(ADVANCE)'], true) && $donor_member_id) {
            $targetTable = ($donor_table === 'sahakari_members') ? 'sahakari_members' : 'members';
            $updMF_sql = "
                UPDATE {$targetTable}
                SET
                    monthly_fee_adv = monthly_fee_adv + GREATEST(0, ? - total_due),
                    total_due = GREATEST(0, total_due - ?),
                    monthly_donation_due = CASE
                        WHEN GREATEST(0, total_due - ?) = 0 THEN 'cleared'
                        ELSE monthly_donation_due
                    END
                WHERE id = ?
            ";
            $updMF = $conn->prepare($updMF_sql);
            if ($updMF) {
                $updMF->bind_param("dddi", $d_amount, $d_amount, $d_amount, $d_donor_id);
                if (!$updMF->execute()) {
                    $updMF->close();
                    throw new Exception("Failed to update dues/advance for MONTHLY FEE: " . $updMF->error);
                }
                $updMF->close();
            } else {
                throw new Exception("Failed to prepare MONTHLY FEE update: " . $conn->error);
            }
        }

        // Also update total_donations_received for donors when applicable (exempt categories excluded)
        $exempt = ['FRIDAY INCOME', 'ROOM RENT', 'CASH DEPOSIT', 'NERCHE PETTI'];

        if (!in_array($category, $exempt) && $donor_member_id && $donor_table !== null) {
            $targetTable = ($donor_table === 'sahakari_members') ? 'sahakari_members' : 'members';
            $upd2_sql = "UPDATE {$targetTable} SET total_donations_received = total_donations_received + ? WHERE id = ?";
            $upd2 = $conn->prepare($upd2_sql);
            if ($upd2) {
                $upd2->bind_param("di", $d_amount, $d_donor_id);
                $upd2->execute();
                $upd2->close();
            }
        }

        // If this is an EXPENSE of SALARY and staff_id provided -> mark staff as paid + record date & amount
        if ($type === 'EXPENSE' && strtoupper($category) === 'SALARY' && $staff_id) {
            $chkStaff = $conn->prepare("SELECT id, mahal_id FROM staff WHERE id = ? AND mahal_id = ?");
            if ($chkStaff) {
                $chkStaff->bind_param("ii", $d_staff_id, $user_id);
                $chkStaff->execute();
                $chkRes = $chkStaff->get_result();
                $chkRow = $chkRes->fetch_assoc();
                $chkStaff->close();
            } else {
                throw new Exception("Failed to verify staff: " . $conn->error);
            }

            if (!empty($chkRow)) {
                $updStaff = $conn->prepare("UPDATE staff SET salary_payment_status = 'paid', last_salary_paid_date = ?, last_salary_paid_amount = ? WHERE id = ? AND mahal_id = ?");
                if ($updStaff) {
                    $today = date('Y-m-d');
                    $updStaff->bind_param("sdii", $today, $d_amount, $d_staff_id, $user_id);
                    if (!$updStaff->execute()) {
                        $updStaff->close();
                        throw new Exception("Failed to update staff salary record: " . $updStaff->error);
                    }
                    $updStaff->close();

                    // Insert into staff_payments table
                    $paySql = "INSERT INTO staff_payments (mahal_id, staff_id, amount, transaction_date, payment_mode, description)
                               VALUES (?, ?, ?, NOW(), ?, ?)";
                    $payStmt = $conn->prepare($paySql);
                    if (!$payStmt) {
                        throw new Exception("Failed to prepare staff_payments insert: " . $conn->error);
                    }
                    if (!$payStmt->bind_param("iidss", $user_id, $d_staff_id, $d_amount, $d_payment_mode, $d_desc)) {
                        $payStmt->close();
                        throw new Exception("Failed to bind staff_payments params: " . $conn->error);
                    }
                    if (!$payStmt->execute()) {
                        $payStmt->close();
                        throw new Exception("Failed to insert staff payment: " . $payStmt->error);
                    }
                    $payStmt->close();
                } else {
                    throw new Exception("Failed to prepare staff update: " . $conn->error);
                }
            } else {
                throw new Exception("Selected staff does not belong to this mahal");
            }
        }

        $conn->commit();

        // Build receipt URL
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
            . "://" . $_SERVER['HTTP_HOST']
            . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

        $receipt_url = $baseUrl . "/finance-api.php?action=receipt&id=" . urlencode($insertId) . "&user_id=" . urlencode($user_id);

        echo json_encode([
            'success' => true,
            'message' => 'Transaction saved successfully',
            'id' => $insertId,
            'receipt_url' => $receipt_url
        ]);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

/* ------------------- SAVE BULK transactions (REGULAR / SAHAKARI) ------------------- */ elseif ($action === 'save_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    if (isset($input['error'])) {
        echo json_encode(['success' => false, 'message' => $input['error']]);
        exit();
    }

    $user_id = intval($input['user_id'] ?? 0);
    $date = $input['date'] ?? '';
    $type = strtoupper(trim($input['type'] ?? ''));
    $category = strtoupper(trim($input['category'] ?? ''));
    $payment_mode = strtoupper(trim($input['payment_mode'] ?? 'CASH'));
    if (!in_array($payment_mode, ['CASH', 'G PAY'], true))
        $payment_mode = 'CASH';
    $description = $input['description'] ?? null;
    $other_expense_detail = isset($input['other_expense_detail']) ? trim($input['other_expense_detail']) : null;

    $members = is_array($input['members']) ? $input['members'] : [];
    $bulk_amount = isset($input['amount']) ? floatval($input['amount']) : null;

    // NEW: regular vs sahakari source for bulk
    // Frontend sends member_type = 'REGULAR' | 'SAHAKARI'
    $memberType = strtoupper(trim($input['member_type'] ?? ''));

    // Backward compatibility: if only member_source is sent, use it.
    if ($memberType === '' && !empty($input['member_source'])) {
        $tmp = strtolower(trim($input['member_source']));
        $memberType = ($tmp === 'sahakari') ? 'SAHAKARI' : 'REGULAR';
    }

    if (!in_array($memberType, ['REGULAR', 'SAHAKARI'], true)) {
        $memberType = 'REGULAR';
    }

    $member_source = strtolower($memberType);              // 'regular' or 'sahakari'
    $member_table = ($member_source === 'sahakari')
        ? 'sahakari_members'
        : 'members';

    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }
    if (!$date) {
        echo json_encode(['success' => false, 'message' => 'Missing date']);
        exit();
    }
    if (!$type) {
        echo json_encode(['success' => false, 'message' => 'Missing type']);
        exit();
    }
    if (!$category) {
        echo json_encode(['success' => false, 'message' => 'Missing category']);
        exit();
    }
    if (empty($members) && ($bulk_amount === null || $bulk_amount <= 0)) {
        echo json_encode(['success' => false, 'message' => 'No members provided and no bulk amount specified']);
        exit();
    }

    if ($type === 'EXPENSE' && $category === 'OTHER EXPENSES') {
        if (!$other_expense_detail) {
            echo json_encode(['success' => false, 'message' => 'Please provide Other Expense Detail for OTHER EXPENSES category.']);
            exit();
        }
    }

    // If sahakari is requested, ensure table exists
    if ($member_source === 'sahakari') {
        $hasSah = false;
        if ($rt2 = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
            $hasSah = $rt2->num_rows > 0;
            $rt2->close();
        }
        if (!$hasSah) {
            echo json_encode(['success' => false, 'message' => 'Sahakari members table not found.']);
            exit();
        }
    }

    // Per-mahal per-year receipt counter for bulk (based on transaction_date)
    $transYear = (int) date('Y', strtotime($date));
    $receiptCounter = 0;
    $rcStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
    if ($rcStmt) {
        $rcStmt->bind_param("ii", $user_id, $transYear);
        $rcStmt->execute();
        $rcRes = $rcStmt->get_result();
        if ($rcRow = $rcRes->fetch_assoc()) {
            $receiptCounter = (int) $rcRow['cnt'];
        }
        $rcStmt->close();
    }

    $conn->begin_transaction();
    try {
        $insertSql = "INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, other_expense_detail, donor_member_id, donor_details, payment_mode)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insStmt = $conn->prepare($insertSql);
        if (!$insStmt)
            throw new Exception("Prepare failed (insert): " . $conn->error);

        // Unified MONTHLY FEE + ADVANCE logic for bulk (now supports members AND sahakari_members)
        $upd_monthly_sql = "
            UPDATE {$member_table}
            SET
                monthly_fee_adv = monthly_fee_adv + GREATEST(0, ? - total_due),
                total_due = GREATEST(0, total_due - ?),
                monthly_donation_due = CASE
                    WHEN GREATEST(0, total_due - ?) = 0 THEN 'cleared'
                    ELSE monthly_donation_due
                END
            WHERE id = ?
        ";
        $upd_monthly_stmt = $conn->prepare($upd_monthly_sql);

        $upd_total_received_sql = "UPDATE {$member_table} SET total_donations_received = total_donations_received + ? WHERE id = ?";
        $upd_total_received_stmt = $conn->prepare($upd_total_received_sql);

        $chk_member_sql = "SELECT id FROM {$member_table} WHERE id = ? AND mahal_id = ?";
        $chk_member_stmt = $conn->prepare($chk_member_sql);

        $exempt = ['FRIDAY INCOME', 'ROOM RENT', 'LELAM', 'CASH DEPOSIT', 'NERCHE PETTI'];
        $insertIds = [];

        if (empty($members)) {
            throw new Exception('Members list is empty; provide members array with member_id and amount.');
        }

        foreach ($members as $m) {
            $member_id = isset($m['member_id']) ? intval($m['member_id']) : 0;
            $member_amount = isset($m['amount']) ? floatval($m['amount']) : null;
            $member_person_name = isset($m['person_name']) ? trim($m['person_name']) : null;

            if ($member_id <= 0) {
                throw new Exception("Invalid member_id in members list");
            }

            $amt = ($member_amount !== null && $member_amount > 0) ? $member_amount : $bulk_amount;
            if (!is_numeric($amt) || $amt <= 0) {
                throw new Exception("Invalid amount for member_id {$member_id}");
            }

            // Check that member/sahakari belongs to this mahal
            $chk_member_stmt->bind_param("ii", $member_id, $user_id);
            $chk_member_stmt->execute();
            $member_res = $chk_member_stmt->get_result();
            if (!$member_res || $member_res->num_rows === 0) {
                throw new Exception("Selected " . ($member_source === 'sahakari' ? 'sahakari ' : '') . "member {$member_id} does not belong to this mahal");
            }

            $u = $user_id;
            $d_date = $date;
            $d_type = $type;
            $d_cat = $category;
            $d_amount = $amt;
            $d_desc = $description;
            $d_other = $other_expense_detail;
            $d_donor_id = $member_id;
            $d_donor_details = $member_person_name;
            $d_payment_mode = $payment_mode;

            $insStmt->bind_param(
                "isssdssiss",
                $u,
                $d_date,
                $d_type,
                $d_cat,
                $d_amount,
                $d_desc,
                $d_other,
                $d_donor_id,
                $d_donor_details,
                $d_payment_mode
            );

            if (!$insStmt->execute()) {
                throw new Exception("Failed to insert transaction for member {$member_id}: " . $insStmt->error);
            }
            $newId = $conn->insert_id;
            $insertIds[] = $newId;

            // Assign receipt/voucher number per newly inserted transaction
            $receiptCounter++;
            $receiptPrefix = ($type === 'EXPENSE') ? 'V' : 'R';
            $receiptNumber = $receiptPrefix . $receiptCounter . '/' . $transYear;
            $updReceipt = $conn->prepare("UPDATE transactions SET receipt_no = ? WHERE id = ?");

            if ($updReceipt) {
                $updReceipt->bind_param("si", $receiptNumber, $newId);
                if (!$updReceipt->execute()) {
                    $updReceipt->close();
                    throw new Exception("Failed to update receipt number for member {$member_id}: " . $updReceipt->error);
                }
                $updReceipt->close();
            } else {
                throw new Exception("Failed to prepare receipt number update: " . $conn->error);
            }

            // MONTHLY FEE / ADVANCE handling in bulk (members or sahakari_members)
            if ($type === 'INCOME' && in_array(strtoupper($category), ['MONTHLY FEE', 'MONTHLY FEE(ADVANCE)'], true)) {
                if ($upd_monthly_stmt) {
                    $upd_monthly_stmt->bind_param("dddi", $d_amount, $d_amount, $d_amount, $d_donor_id);
                    if (!$upd_monthly_stmt->execute()) {
                        throw new Exception("Failed to update advance/due for {$member_table} id {$d_donor_id}: " . $upd_monthly_stmt->error);
                    }
                }
            }

            // Update total_donations_received (for both members & sahakari) except exempt categories
            if (!in_array($category, $exempt) && $member_id) {
                if ($upd_total_received_stmt) {
                    $upd_total_received_stmt->bind_param("di", $d_amount, $d_donor_id);
                    if (!$upd_total_received_stmt->execute()) {
                        throw new Exception("Failed to update total_donations_received for {$member_table} id {$d_donor_id}: " . $upd_total_received_stmt->error);
                    }
                }
            }
        }

        $insStmt->close();
        if ($upd_monthly_stmt)
            $upd_monthly_stmt->close();
        if ($upd_total_received_stmt)
            $upd_total_received_stmt->close();
        if ($chk_member_stmt)
            $chk_member_stmt->close();

        $conn->commit();

        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
            . "://" . $_SERVER['HTTP_HOST']
            . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

        $idsParam = implode(',', $insertIds);
        $receipt_url = $baseUrl . "/finance-api.php?action=bulk_receipt&ids=" . urlencode($idsParam) . "&user_id=" . urlencode($user_id);

        echo json_encode([
            'success' => true,
            'message' => 'Bulk transactions saved successfully',
            'inserted_ids' => $insertIds,
            'receipt_url' => $receipt_url
        ]);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

/* ------------------- DELETE transaction ------------------- */ elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    $user_id = intval($input['user_id'] ?? 0);

    if ($id <= 0 || $user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing id or user_id']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $fetch = $conn->prepare("SELECT amount, donor_member_id, category, type, staff_id FROM transactions WHERE id = ? AND user_id = ?");
        if (!$fetch)
            throw new Exception("Prepare fetch failed: " . $conn->error);
        $fetch->bind_param("ii", $id, $user_id);
        $fetch->execute();
        $res = $fetch->get_result();
        if ($row = $res->fetch_assoc()) {
            $amount = floatval($row['amount']);
            $donor_member_id = $row['donor_member_id'] ? intval($row['donor_member_id']) : null;
            $category = strtoupper($row['category'] ?? '');
            $type = strtoupper($row['type'] ?? '');
            $staff_id = isset($row['staff_id']) ? (int) $row['staff_id'] : null;
        } else {
            $fetch->close();
            throw new Exception("Transaction not found");
        }
        $fetch->close();

        // Figure out which table this donor belongs to (members or sahakari_members)
        $donor_table = null;
        if ($donor_member_id) {
            $mahal_id = (int) $_SESSION['user_id'];
            $donor_table = resolve_donor_table($conn, $donor_member_id, $mahal_id);
        }

        $del = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        if (!$del)
            throw new Exception("Prepare delete failed: " . $conn->error);
        $del->bind_param("ii", $id, $user_id);
        if (!$del->execute()) {
            throw new Exception("Failed to delete transaction: " . $del->error);
        }
        $del->close();

        $exempt = ['FRIDAY INCOME', 'ROOM RENT', 'CASH DEPOSIT', 'NERCHE PETTI'];

        // Reverse total_donations_received in correct table
        if (!in_array($category, $exempt) && $donor_member_id && $donor_table !== null) {
            $targetTable = ($donor_table === 'sahakari_members') ? 'sahakari_members' : 'members';
            $rev_sql = "UPDATE {$targetTable} SET total_donations_received = GREATEST(0, total_donations_received - ?) WHERE id = ?";
            $rev = $conn->prepare($rev_sql);
            if ($rev) {
                $rev->bind_param("di", $amount, $donor_member_id);
                $rev->execute();
                $rev->close();
            }
        }

        // Reverse MONTHLY FEE / MONTHLY FEE(ADVANCE) with advance-handling (regular + sahakari)
        if (
            $type === 'INCOME'
            && in_array($category, ['MONTHLY FEE', 'MONTHLY FEE(ADVANCE)'], true)
            && $donor_member_id
            && $donor_table !== null
        ) {
            $targetTable = ($donor_table === 'sahakari_members') ? 'sahakari_members' : 'members';

            // Fetch current due and advance for this member (FOR UPDATE since we're in a transaction)
            $sel = $conn->prepare("SELECT total_due, monthly_fee_adv FROM {$targetTable} WHERE id = ? FOR UPDATE");
            if (!$sel) {
                throw new Exception("Prepare failed for fetching member for monthly fee reverse: " . $conn->error);
            }
            $sel->bind_param("i", $donor_member_id);
            $sel->execute();
            $mrow = $sel->get_result()->fetch_assoc();
            $sel->close();

            if ($mrow) {
                $curDue = isset($mrow['total_due']) ? (float) $mrow['total_due'] : 0.0;
                $curAdv = isset($mrow['monthly_fee_adv']) ? (float) $mrow['monthly_fee_adv'] : 0.0;
                $amtToReverse = (float) $amount;

                // 1) Use advance first
                if ($curAdv >= $amtToReverse) {
                    // Advance fully absorbs the deleted monthly fee
                    $newAdv = $curAdv - $amtToReverse;
                    $newDue = $curDue;
                } else {
                    // Advance exhausted, leftover becomes new due
                    $newAdv = 0.0;
                    $remaining = $amtToReverse - $curAdv;
                    $newDue = $curDue + $remaining;
                }

                // 2) Update member / sahakari record
                $upd2 = $conn->prepare("
                    UPDATE {$targetTable}
                    SET 
                        monthly_fee_adv = ?,
                        total_due = ?,
                        monthly_donation_due = CASE 
                            WHEN ? > 0 THEN 'due' 
                            ELSE monthly_donation_due 
                        END
                    WHERE id = ?
                ");
                if (!$upd2) {
                    throw new Exception("Prepare failed for reversing monthly fee / advance: " . $conn->error);
                }
                $upd2->bind_param("dddi", $newAdv, $newDue, $newDue, $donor_member_id);
                if (!$upd2->execute()) {
                    $upd2->close();
                    throw new Exception("Failed to reverse monthly fee / advance on member: " . $upd2->error);
                }
                $upd2->close();
            }
        }

        // Reverse salary record & staff_payments if needed
        if ($type === 'EXPENSE' && $category === 'SALARY' && !empty($staff_id)) {
            $revStaff = $conn->prepare("
                UPDATE staff 
                SET 
                    last_salary_paid_amount = GREATEST(0, last_salary_paid_amount - ?),
                    salary_payment_status = CASE WHEN GREATEST(0, last_salary_paid_amount - ?) = 0 THEN 'unpaid' ELSE salary_payment_status END,
                    last_salary_paid_date = CASE WHEN GREATEST(0, last_salary_paid_amount - ?) = 0 THEN NULL ELSE last_salary_paid_date END
                WHERE id = ? AND mahal_id = ?
            ");
            if ($revStaff) {
                $revStaff->bind_param("ddiii", $amount, $amount, $amount, $staff_id, $user_id);
                if (!$revStaff->execute()) {
                    $revStaff->close();
                    throw new Exception("Failed to reverse staff salary record: " . $revStaff->error);
                }
                $revStaff->close();
            }

            $delPay = $conn->prepare("DELETE FROM staff_payments WHERE mahal_id = ? AND staff_id = ? AND amount = ? LIMIT 1");
            if ($delPay) {
                $delPay->bind_param("iid", $user_id, $staff_id, $amount);
                $delPay->execute();
                $delPay->close();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

/* ------------------- DOWNLOAD statement (CSV) ------------------- */ elseif ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $userSQL = "SELECT name, registration_no FROM register WHERE id = ?";
    $userStmt = $conn->prepare($userSQL);
    $userStmt->bind_param("i", $user_id);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userData = $userResult->fetch_assoc();
    $mahalName = $userData['name'] ?? 'Mahal';
    $regNo = $userData['registration_no'] ?? '';
    $userStmt->close();

    $sql = "SELECT t.*, m.head_name as donor_head_name
            FROM transactions t
            LEFT JOIN members m ON t.donor_member_id = m.id
            WHERE t.user_id = ? AND t.transaction_date BETWEEN ? AND ?
            ORDER BY t.transaction_date ASC, t.id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();

    $transactions = [];
    $totalIncome = 0;
    $totalExpense = 0;

    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
        if ($row['type'] === 'INCOME')
            $totalIncome += $row['amount'];
        else
            $totalExpense += $row['amount'];
    }
    $stmt->close();

    $balance = $totalIncome - $totalExpense;

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financial_statement_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [$mahalName . ' - Financial Statement']);
    if ($regNo) {
        fputcsv($output, ['Registration No: ' . $regNo]);
    }
    fputcsv($output, ['Period: ' . date('d-m-Y', strtotime($from)) . ' to ' . date('d-m-Y', strtotime($to))]);
    fputcsv($output, ['Generated: ' . date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['FINANCIAL SUMMARY']);
    fputcsv($output, ['Total Income', 'Rs. ' . number_format($totalIncome, 2)]);
    fputcsv($output, ['Total Expenses', 'Rs. ' . number_format($totalExpense, 2)]);
    fputcsv($output, ['Current Balance', 'Rs. ' . number_format($balance, 2)]);
    fputcsv($output, []);
    fputcsv($output, []);
    fputcsv($output, ['ID', 'Receipt No', 'Date', 'Type', 'Category', 'Description', 'Other Expense Detail', 'Amount (Rs.)', 'Donor (person)', 'Donor (head)', 'Payment Mode', 'Staff (recipient)']);

    foreach ($transactions as $transaction) {
        $staffName = '-';
        if (!empty($transaction['staff_id'])) {
            $s = $conn->prepare("SELECT name FROM staff WHERE id = ?");
            if ($s) {
                $s->bind_param("i", $transaction['staff_id']);
                $s->execute();
                $sr = $s->get_result()->fetch_assoc();
                if ($sr)
                    $staffName = $sr['name'];
                $s->close();
            }
        }

        fputcsv($output, [
            $transaction['id'],
            $transaction['receipt_no'] ?? '-',
            date('d-m-Y', strtotime($transaction['transaction_date'])),
            $transaction['type'],
            $transaction['category'],
            $transaction['description'] ?? '-',
            $transaction['other_expense_detail'] ?? '-',
            number_format($transaction['amount'], 2),
            $transaction['donor_details'] ?? '-',
            $transaction['donor_head_name'] ?? '-',
            $transaction['payment_mode'] ?? 'CASH',
            $staffName
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['', '', '', '', '', 'Total Income:', number_format($totalIncome, 2)]);
    fputcsv($output, ['', '', '', '', '', 'Total Expenses:', number_format($totalExpense, 2)]);
    fputcsv($output, ['', '', '', '', '', 'Balance:', number_format($balance, 2)]);

    fclose($output);
    exit();
}

/* ------------------- GET summary statistics ------------------- */ elseif ($action === 'get_summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $sql = "SELECT type, COUNT(*) as transaction_count, SUM(amount) as total_amount
            FROM transactions
            WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
            GROUP BY type";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();

    $summary = ['income' => 0, 'expense' => 0, 'balance' => 0, 'income_count' => 0, 'expense_count' => 0];
    while ($row = $result->fetch_assoc()) {
        if ($row['type'] === 'INCOME') {
            $summary['income'] = floatval($row['total_amount']);
            $summary['income_count'] = intval($row['transaction_count']);
        } else {
            $summary['expense'] = floatval($row['total_amount']);
            $summary['expense_count'] = intval($row['transaction_count']);
        }
    }
    $summary['balance'] = $summary['income'] - $summary['expense'];

    echo json_encode(['success' => true, 'summary' => $summary]);
    $stmt->close();
    exit();
}

/* ------------------- GET category-wise summary ------------------- */ elseif ($action === 'get_category_summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $type = strtoupper(trim($_GET['type'] ?? ''));

    $sql = "SELECT category, COUNT(*) as transaction_count, SUM(amount) as total_amount
            FROM transactions
            WHERE user_id = ?";
    if ($type)
        $sql .= " AND type = ?";
    $sql .= " GROUP BY category ORDER BY total_amount DESC";

    if ($type) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $type);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $categories = [];
    while ($row = $result->fetch_assoc())
        $categories[] = $row;

    echo json_encode(['success' => true, 'categories' => $categories]);
    $stmt->close();
    exit();
}

/* ------------------- GET bulk members for Bulk Transaction ------------------- */
/* UPDATED: support regular vs sahakari, and use monthly_fee as default_amount */ elseif ($action === 'get_bulk_members' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }

    // Frontend sends member_type = 'REGULAR' | 'SAHAKARI'
    $memberType = strtoupper(trim($_GET['member_type'] ?? ''));

    // Backward compatibility: if only member_source is sent, use it.
    if ($memberType === '' && isset($_GET['member_source'])) {
        $tmp = strtolower(trim($_GET['member_source']));
        $memberType = ($tmp === 'sahakari') ? 'SAHAKARI' : 'REGULAR';
    }

    if (!in_array($memberType, ['REGULAR', 'SAHAKARI'], true)) {
        $memberType = 'REGULAR';
    }

    if ($memberType === 'SAHAKARI') {
        // Ensure sahakari table exists
        $hasSah = false;
        if ($rt2 = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
            $hasSah = $rt2->num_rows > 0;
            $rt2->close();
        }
        if (!$hasSah) {
            echo json_encode(['success' => true, 'members' => []]);
            exit();
        }
        $table = 'sahakari_members';
    } else {
        $table = 'members';
    }

    /*
     * bulk_print_enabled: boolean flag for including in bulk
     * monthly_fee: per-member monthly fee amount
     */
    $sql = "SELECT id, head_name, member_number, COALESCE(monthly_fee, 0) AS default_amount
            FROM {$table}
            WHERE mahal_id = ? 
              AND bulk_print_enabled = 1
            ORDER BY head_name ASC
            LIMIT 2000";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'members' => $members]);
    exit();
}

/* ------------------- GET balance by payment mode ------------------- */ elseif ($action === 'get_balance_by_mode' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }

    $sql = "SELECT payment_mode, 
                   SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN type = 'EXPENSE' THEN amount ELSE 0 END) AS expense,
                   SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'EXPENSE' THEN amount ELSE 0 END) AS balance
            FROM transactions
            WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
            GROUP BY payment_mode";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("iss", $user_id, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $balances = [];
    while ($row = $res->fetch_assoc()) {
        $balances[] = [
            'payment_mode' => $row['payment_mode'],
            'income' => floatval($row['income']),
            'expense' => floatval($row['expense']),
            'balance' => floatval($row['balance'])
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'balances' => $balances]);
    exit();
}

/* ------------------- GET staff for salary (used by UI) ------------------- */ elseif ($action === 'get_staff_for_salary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = intval($_GET['user_id'] ?? 0);
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id']);
        exit();
    }

    $sql = "SELECT s.id AS staff_id, s.name, COALESCE(s.fixed_salary, 0) AS fixed_salary, s.salary_payment_status
            FROM staff s
            WHERE s.mahal_id = ?
            ORDER BY s.name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $staff = [];
    while ($row = $res->fetch_assoc()) {
        $row['fixed_salary'] = floatval($row['fixed_salary']);
        $staff[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'staff' => $staff]);
    exit();
}

/* ------------------- RECEIPT (printable HTML) for single transaction ------------------- */ elseif ($action === 'receipt' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $tid = intval($_GET['id'] ?? 0);
    $user_id = intval($_GET['user_id'] ?? 0);
    if ($tid <= 0 || $user_id <= 0) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid receipt request.";
        exit();
    }

    $sql = "SELECT 
                t.id, t.user_id, t.transaction_date, t.type, t.category, t.amount, t.description, t.other_expense_detail,
                t.donor_details, t.donor_member_id, t.payment_mode, t.staff_id, t.receipt_no,
                m.head_name AS donor_head_name,
                m.member_number AS donor_member_number,
                m.id AS donor_member_internal_id,
                m.address AS donor_member_address,
                r.name AS mahal_name, r.address AS mahal_address, r.registration_no AS reg_no, r.email AS mahal_email
            FROM transactions t
            LEFT JOIN members m ON t.donor_member_id = m.id
            JOIN register r ON r.id = t.user_id
            WHERE t.id = ? AND t.user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Error preparing receipt.";
        exit();
    }
    $stmt->bind_param("ii", $tid, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Receipt not found.";
        exit();
    }
    $t = $res->fetch_assoc();
    $stmt->close();

    // --- Resolve donor from sahakari_members if not found in members ---
    $donorHeadNameRaw = $t['donor_head_name'] ?? null;
    $donorMemberNoRaw = $t['donor_member_number'] ?? null;
    $donorMemberIdRaw = $t['donor_member_internal_id'] ?? null;
    $donorMemberAddrRaw = $t['donor_member_address'] ?? null;

    if (
        !empty($t['donor_member_id']) &&
        empty($donorHeadNameRaw) &&
        empty($donorMemberNoRaw) &&
        empty(trim((string) $donorMemberAddrRaw ?? ''))
    ) {
        // Check if sahakari_members table exists
        $hasSah = false;
        if ($rt = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
            $hasSah = $rt->num_rows > 0;
            $rt->close();
        }
        if ($hasSah) {
            $sid = (int) $t['donor_member_id'];
            $sstmt = $conn->prepare("SELECT id, head_name, member_number, address FROM sahakari_members WHERE id = ? LIMIT 1");
            if ($sstmt) {
                $sstmt->bind_param("i", $sid);
                $sstmt->execute();
                $sr = $sstmt->get_result()->fetch_assoc();
                $sstmt->close();
                if ($sr) {
                    $donorHeadNameRaw = $sr['head_name'] ?? null;
                    $donorMemberNoRaw = $sr['member_number'] ?? null;
                    $donorMemberIdRaw = $sr['id'] ?? null;
                    $donorMemberAddrRaw = $sr['address'] ?? null;
                }
            }
        }
    }

    $mahal = htmlspecialchars($t['mahal_name'] ?? 'Mahal');
    $addr = htmlspecialchars($t['mahal_address'] ?? '');
    $reg = htmlspecialchars($t['reg_no'] ?? '');
    $email = htmlspecialchars($t['mahal_email'] ?? '');

    $dt = date('d-m-Y', strtotime($t['transaction_date']));
    $type = htmlspecialchars($t['type']);
    $cat = htmlspecialchars($t['category']);
    $docLabel = ($t['type'] === 'EXPENSE') ? 'Voucher' : 'Receipt';
    $desc = htmlspecialchars($t['description'] ?? '-');
    $otherDetail = htmlspecialchars($t['other_expense_detail'] ?? '-');
    $amt = number_format((float) $t['amount'], 2);
    $donorPerson = htmlspecialchars($t['donor_details'] ?? '-');

    $donorHeadName = $donorHeadNameRaw ?? '-';
    $donorHead = htmlspecialchars($donorHeadName);
    $donorMemberNo = $donorMemberNoRaw !== null ? (string) $donorMemberNoRaw : '';
    $donorMemberId = $donorMemberIdRaw !== null ? (string) $donorMemberIdRaw : '';
    $donorMemberAddr = trim((string) ($donorMemberAddrRaw ?? ''));

    $payMode = htmlspecialchars($t['payment_mode'] ?? 'CASH');

    $staffName = '';
    if (!empty($t['staff_id'])) {
        $sstmt = $conn->prepare("SELECT name FROM staff WHERE id = ?");
        if ($sstmt) {
            $sstmt->bind_param("i", $t['staff_id']);
            $sstmt->execute();
            $sr = $sstmt->get_result()->fetch_assoc();
            if ($sr)
                $staffName = htmlspecialchars($sr['name']);
            $sstmt->close();
        }
    }

    $fallbackPrefix = ($t['type'] === 'EXPENSE') ? 'V' : 'R';
    $receiptLabel = htmlspecialchars($t['receipt_no'] ?? ($fallbackPrefix . $t['id']));
    $title = $docLabel . " " . $receiptLabel;

    $origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    $selfUrl = $origin . htmlspecialchars($_SERVER['REQUEST_URI']);

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title><?= $title ?> - <?= $mahal ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            :root {
                --ink: #111827;
                --muted: #6b7280;
                --bg: #ffffff;
                --line: #e5e7eb;
                --pri: #2563eb;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                color: var(--ink);
                background: #f3f4f6;
                margin: 0;
                padding: 24px;
            }

            .sheet {
                max-width: 760px;
                margin: 0 auto;
                background: var(--bg);
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 28px;
            }

            .hdr {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 18px;
            }

            .brand h1 {
                font-size: 20px;
                margin: 0 0 4px;
                color: var(--pri);
                font-weight: 800;
                letter-spacing: .2px;
            }

            .brand div {
                font-size: 12px;
                color: var(--muted);
                word-break: break-word;
            }

            .meta {
                text-align: right;
            }

            .meta div {
                font-size: 12px;
                color: var(--muted);
            }

            .badge {
                display: inline-block;
                padding: 6px 10px;
                border-radius: 999px;
                font-weight: 700;
                font-size: 12px;
                background: #d1fae5;
                color: #065f46;
            }

            .badge.expense {
                background: #fee2e2;
                color: #991b1b;
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                margin: 16px 0 8px;
            }

            .card {
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 12px;
            }

            .card h3 {
                margin: 0 0 8px;
                font-size: 12px;
                color: var(--muted);
                letter-spacing: .3px;
                text-transform: uppercase;
            }

            .row {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                padding: 10px 0;
                border-bottom: 1px dashed var(--line);
            }

            .row:last-child {
                border-bottom: none;
            }

            .label {
                color: var(--muted);
                font-size: 12px;
                min-width: 140px;
            }

            .val {
                font-weight: 700;
                word-break: break-word;
                white-space: pre-wrap;
            }

            .val.desc {
                font-weight: 400;
                font-size: 12px;
                color: #000;
                white-space: pre-wrap;
                word-break: break-word;
            }

            .amt {
                font-size: 20px;
                font-weight: 800;
            }

            .actions {
                display: flex;
                gap: 10px;
                margin-top: 18px;
            }

            .btn {
                border: 1px solid var(--line);
                background: #fff;
                padding: 10px 14px;
                border-radius: 10px;
                cursor: pointer;
                font-weight: 600;
            }

            .btn:hover {
                background: #f9fafb;
            }

            .foot {
                margin-top: 18px;
                font-size: 12px;
                color: var(--muted);
                text-align: center;
            }

            @media print {
                @page {
                    size: A4 portrait;
                    margin: 0;
                }

                html,
                body {
                    background: #fff;
                    padding: 0;
                    margin: 0;
                    width: 210mm;
                }

                body {
                    padding: 8mm;
                }

                .sheet {
                    max-width: 100%;
                    width: 100%;
                    height: 138.5mm;
                    overflow: hidden;
                    border: none;
                    border-radius: 0;
                    padding: 16px;
                    box-sizing: border-box;
                }

                .actions {
                    display: none !important;
                }

                .foot {
                    margin-top: 8px;
                }
            }
        </style>
    </head>

    <body>
        <div class="sheet">
            <div class="hdr">
                <div class="brand">
                    <h1><?= $mahal ?></h1>
                    <div>
                        <?php if ($addr)
                            echo $addr . ' · '; ?>
                        <?php if ($reg)
                            echo 'Reg: ' . $reg . ' · '; ?>
                        <?php if ($email)
                            echo $email; ?>
                    </div>
                </div>
                <div class="meta">
                    <div><?= $title ?></div>
                    <div>Date: <?= $dt ?></div>
                </div>
            </div>

            <div class="grid">
                <div class="card">
                    <h3>Transaction</h3>
                    <div class="row">
                        <div class="label">Type</div>
                        <div class="val">
                            <span class="badge <?= $type === 'EXPENSE' ? 'expense' : '' ?>"><?= $type ?></span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="label">Category</div>
                        <div class="val"><?= $cat ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Description</div>
                        <div class="val desc"><?= $desc ?></div>
                    </div>
                    <?php if ($otherDetail && $otherDetail !== '-'): ?>
                        <div class="row">
                            <div class="label">Other Expense Detail</div>
                            <div class="val"><?= $otherDetail ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h3>Amount</h3>
                    <div class="row">
                        <div class="label">Amount (INR)</div>
                        <div class="val amt">₹<?= $amt ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Payment Mode</div>
                        <div class="val"><?= $payMode ?></div>
                    </div>
                    <div class="row">
                        <div class="label"><?= $docLabel ?> ID</div>
                        <div class="val"><?= $receiptLabel ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Recorded On</div>
                        <div class="val"><?= date('d-m-Y H:i') ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($t['donor_details']) || !empty($t['donor_member_id'])): ?>
                <div class="card">
                    <h3>Donor</h3>
                    <div class="row">
                        <div class="label">Person</div>
                        <div class="val"><?= $donorPerson ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Head</div>
                        <div class="val"><?= $donorHead ?></div>
                    </div>

                    <?php if (!empty($t['donor_member_id'])): ?>
                        <?php
                        $labelForId = $donorMemberNo !== '' ? 'Member No' : 'Member ID';
                        $idValue = $donorMemberNo !== '' ? htmlspecialchars($donorMemberNo) : htmlspecialchars($donorMemberId);
                        ?>
                        <div class="row">
                            <div class="label"><?= $labelForId ?></div>
                            <div class="val"><?= $idValue ?></div>
                        </div>
                        <?php if ($donorMemberAddr !== ''): ?>
                            <div class="row">
                                <div class="label">Address</div>
                                <div class="val"><?= htmlspecialchars($donorMemberAddr) ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($staffName): ?>
                <div class="card">
                    <h3>Staff Recipient</h3>
                    <div class="row">
                        <div class="label">Staff</div>
                        <div class="val"><?= $staffName ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button class="btn" onclick="window.print()">Print</button>
                <button class="btn" id="shareBtn">Share</button>
                <button class="btn" id="copyBtn">Copy Link</button>
            </div>

            <div class="foot">
                This is a system-generated <?= strtolower($docLabel) ?> for your records.
            </div>
        </div>

        <script>
            const receiptUrl = <?= json_encode($selfUrl) ?>;
            const docLabel = <?= json_encode($docLabel) ?>;
            document.getElementById('shareBtn').addEventListener('click', async () => {
                if (navigator.share) {
                    try {
                        await navigator.share({ title: document.title, text: docLabel, url: receiptUrl });
                    } catch (e) { }
                } else {
                    alert('Share not supported on this device.');
                }
            });
            document.getElementById('copyBtn').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(receiptUrl);
                    alert('Link copied to clipboard!');
                } catch (e) { alert('Copy failed.'); }
            });
        </script>
    </body>

    </html>
    <?php
    exit();
}

/* ------------------- BULK RECEIPT (printable HTML for multiple receipts) ------------------- */ elseif ($action === 'bulk_receipt' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $ids_raw = trim($_GET['ids'] ?? '');
    $user_id = intval($_GET['user_id'] ?? 0);

    if (!$ids_raw || $user_id <= 0) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid bulk receipt request.";
        exit();
    }

    $ids_arr = array_values(array_filter(array_map('intval', explode(',', $ids_raw))));
    if (empty($ids_arr)) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "No valid receipt IDs provided.";
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($ids_arr), '?'));
    $sql = "SELECT 
                t.id, t.user_id, t.transaction_date, t.type, t.category, t.amount, t.description, t.other_expense_detail,
                t.donor_details, t.donor_member_id, t.payment_mode, t.staff_id, t.receipt_no,
                m.head_name AS donor_head_name,
                m.member_number AS donor_member_number,
                m.id AS donor_member_internal_id,
                m.address AS donor_member_address,
                r.name AS mahal_name, r.address AS mahal_address, r.registration_no AS reg_no, r.email AS mahal_email
            FROM transactions t
            LEFT JOIN members m ON t.donor_member_id = m.id
            JOIN register r ON r.id = t.user_id
            WHERE t.user_id = ? AND t.id IN ($placeholders)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Error preparing bulk receipt: " . $conn->error;
        exit();
    }

    $bind_params = array_merge([$user_id], $ids_arr);
    $types = str_repeat('i', count($bind_params));
    $refs = [];
    foreach ($bind_params as $k => $v) {
        $refs[$k] = &$bind_params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[$r['id']] = $r;
    }
    $stmt->close();

    $ordered = [];
    foreach ($ids_arr as $id) {
        if (isset($rows[$id]))
            $ordered[] = $rows[$id];
    }

    // One-time check for sahakari_members table
    $hasSahBulk = false;
    if ($rt = $conn->query("SHOW TABLES LIKE 'sahakari_members'")) {
        $hasSahBulk = $rt->num_rows > 0;
        $rt->close();
    }

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title>Bulk Receipts</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            :root {
                --ink: #111827;
                --muted: #6b7280;
                --bg: #ffffff;
                --line: #e5e7eb;
                --pri: #2563eb;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                color: var(--ink);
                background: #f3f4f6;
                margin: 0;
                padding: 24px;
            }

            .sheet {
                max-width: 760px;
                margin: 0 auto 24px;
                background: var(--bg);
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 28px;
                page-break-after: always;
            }

            .hdr {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 18px;
            }

            .brand h1 {
                font-size: 20px;
                margin: 0 0 4px;
                color: var(--pri);
                font-weight: 800;
                letter-spacing: .2px;
            }

            .brand div {
                font-size: 12px;
                color: var(--muted);
                word-break: break-word;
            }

            .meta {
                text-align: right;
            }

            .meta div {
                font-size: 12px;
                color: var(--muted);
            }

            .badge {
                display: inline-block;
                padding: 6px 10px;
                border-radius: 999px;
                font-weight: 700;
                font-size: 12px;
                background: #d1fae5;
                color: #065f46;
            }

            .badge.expense {
                background: #fee2e2;
                color: #991b1b;
            }

            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                margin: 16px 0 8px;
            }

            .card {
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 12px;
            }

            .card h3 {
                margin: 0 0 8px;
                font-size: 12px;
                color: var(--muted);
                letter-spacing: .3px;
                text-transform: uppercase;
            }

            .row {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                padding: 10px 0;
                border-bottom: 1px dashed var(--line);
            }

            .row:last-child {
                border-bottom: none;
            }

            .label {
                color: var(--muted);
                font-size: 12px;
                min-width: 140px;
            }

            .val {
                font-weight: 700;
                word-break: break-word;
                white-space: pre-wrap;
            }

            .val.desc {
                font-weight: 400;
                font-size: 12px;
                color: #000;
                white-space: pre-wrap;
                word-break: break-word;
            }

            .amt {
                font-size: 20px;
                font-weight: 800;
            }

            .foot {
                margin-top: 18px;
                font-size: 12px;
                color: var(--muted);
                text-align: center;
            }

            .top-actions {
                max-width: 760px;
                margin: 0 auto 16px;
                text-align: right;
            }

            .btn {
                border: 1px solid var(--line);
                background: #fff;
                padding: 8px 12px;
                border-radius: 10px;
                cursor: pointer;
                font-weight: 600;
                font-size: 13px;
            }

            .btn:hover {
                background: #f9fafb;
            }

            @media print {
                @page {
                    size: A4 portrait;
                    margin: 0;
                }

                html,
                body {
                    background: #fff;
                    padding: 0;
                    margin: 0;
                    width: 210mm;
                }

                body {
                    padding: 8mm;
                }

                .top-actions {
                    display: none !important;
                }

                .sheet {
                    max-width: 100%;
                    width: 100%;
                    height: 138.5mm;
                    overflow: hidden;
                    border: none;
                    border-radius: 0;
                    padding: 16px;
                    box-sizing: border-box;
                    page-break-after: always;
                }

                .foot {
                    margin-top: 8px;
                }
            }
        </style>
    </head>

    <body>

        <?php if (!empty($ordered)): ?>
            <div class="top-actions">
                <button class="btn" onclick="window.print()">Print All</button>
            </div>
        <?php endif; ?>

        <?php
        if (empty($ordered)) {
            echo "<div style='max-width:760px;margin:20px auto;background:#fff;padding:18px;border-radius:12px;border:1px solid #eee;'>No receipts found.</div>";
            exit();
        }

        foreach ($ordered as $t) {
            $mahal = htmlspecialchars($t['mahal_name'] ?? 'Mahal');
            $addr = htmlspecialchars($t['mahal_address'] ?? '');
            $reg = htmlspecialchars($t['reg_no'] ?? '');
            $email = htmlspecialchars($t['mahal_email'] ?? '');

            $dt = date('d-m-Y', strtotime($t['transaction_date']));
            $type = htmlspecialchars($t['type']);
            $cat = htmlspecialchars($t['category']);
            $docLabel = ($t['type'] === 'EXPENSE') ? 'Voucher' : 'Receipt';

            $desc = htmlspecialchars($t['description'] ?? '-');
            $otherDetail = htmlspecialchars($t['other_expense_detail'] ?? '-');
            $amt = number_format((float) $t['amount'], 2);
            $donorPerson = htmlspecialchars($t['donor_details'] ?? '-');

            // Resolve donor from members / sahakari_members
            $donorHeadNameRaw = $t['donor_head_name'] ?? null;
            $donorMemberNoRaw = $t['donor_member_number'] ?? null;
            $donorMemberIdRaw = $t['donor_member_internal_id'] ?? null;
            $donorMemberAddrRaw = $t['donor_member_address'] ?? null;

            if (
                !empty($t['donor_member_id']) &&
                empty($donorHeadNameRaw) &&
                empty($donorMemberNoRaw) &&
                empty(trim((string) $donorMemberAddrRaw ?? '')) &&
                $hasSahBulk
            ) {
                $sid = (int) $t['donor_member_id'];
                $sstmt = $conn->prepare("SELECT id, head_name, member_number, address FROM sahakari_members WHERE id = ? LIMIT 1");
                if ($sstmt) {
                    $sstmt->bind_param("i", $sid);
                    $sstmt->execute();
                    $sr = $sstmt->get_result()->fetch_assoc();
                    $sstmt->close();
                    if ($sr) {
                        $donorHeadNameRaw = $sr['head_name'] ?? null;
                        $donorMemberNoRaw = $sr['member_number'] ?? null;
                        $donorMemberIdRaw = $sr['id'] ?? null;
                        $donorMemberAddrRaw = $sr['address'] ?? null;
                    }
                }
            }

            $donorHeadName = $donorHeadNameRaw ?? '-';
            $donorHead = htmlspecialchars($donorHeadName);
            $donorMemberNo = $donorMemberNoRaw !== null ? (string) $donorMemberNoRaw : '';
            $donorMemberId = $donorMemberIdRaw !== null ? (string) $donorMemberIdRaw : '';
            $donorMemberAddr = trim((string) ($donorMemberAddrRaw ?? ''));

            $payMode = htmlspecialchars($t['payment_mode'] ?? 'CASH');

            $staffName = '';
            if (!empty($t['staff_id'])) {
                $sstmt = $conn->prepare("SELECT name FROM staff WHERE id = ?");
                if ($sstmt) {
                    $sstmt->bind_param("i", $t['staff_id']);
                    $sstmt->execute();
                    $sr = $sstmt->get_result()->fetch_assoc();
                    if ($sr)
                        $staffName = htmlspecialchars($sr['name']);
                    $sstmt->close();
                }
            }

            $fallbackPrefix = ($t['type'] === 'EXPENSE') ? 'V' : 'R';
            $receiptId = htmlspecialchars($t['receipt_no'] ?? ($fallbackPrefix . $t['id']));
            $title = $docLabel . " " . $receiptId;
            ?>
            <div class="sheet">
                <div class="hdr">
                    <div class="brand">
                        <h1><?= $mahal ?></h1>
                        <div>
                            <?php if ($addr)
                                echo $addr . ' · '; ?>
                            <?php if ($reg)
                                echo 'Reg: ' . $reg . ' · '; ?>
                            <?php if ($email)
                                echo $email; ?>
                        </div>
                    </div>
                    <div class="meta">
                        <div><?= $title ?></div>
                        <div>Date: <?= $dt ?></div>
                    </div>
                </div>

                <div class="grid">
                    <div class="card">
                        <h3>Transaction</h3>
                        <div class="row">
                            <div class="label">Type</div>
                            <div class="val">
                                <span class="badge <?= $type === 'EXPENSE' ? 'expense' : '' ?>"><?= $type ?></span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="label">Category</div>
                            <div class="val"><?= $cat ?></div>
                        </div>
                        <div class="row">
                            <div class="label">Description</div>
                            <div class="val desc"><?= $desc ?></div>
                        </div>
                        <?php if ($otherDetail && $otherDetail !== '-'): ?>
                            <div class="row">
                                <div class="label">Other Expense Detail</div>
                                <div class="val"><?= $otherDetail ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card">
                        <h3>Amount</h3>
                        <div class="row">
                            <div class="label">Amount (INR)</div>
                            <div class="val amt">₹<?= $amt ?></div>
                        </div>
                        <div class="row">
                            <div class="label">Payment Mode</div>
                            <div class="val"><?= $payMode ?></div>
                        </div>
                        <div class="row">
                            <div class="label"><?= $docLabel ?> ID</div>
                            <div class="val"><?= $receiptId ?></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>Donor</h3>
                    <div class="row">
                        <div class="label">Person</div>
                        <div class="val"><?= $donorPerson ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Head</div>
                        <div class="val"><?= $donorHead ?></div>
                    </div>

                    <?php if (!empty($t['donor_member_id'])): ?>
                        <?php
                        $labelForId = $donorMemberNo !== '' ? 'Member No' : 'Member ID';
                        $idValue = $donorMemberNo !== '' ? htmlspecialchars($donorMemberNo) : htmlspecialchars($donorMemberId);
                        ?>
                        <div class="row">
                            <div class="label"><?= $labelForId ?></div>
                            <div class="val"><?= $idValue ?></div>
                        </div>
                        <?php if ($donorMemberAddr !== ''): ?>
                            <div class="row">
                                <div class="label">Address</div>
                                <div class="val"><?= htmlspecialchars($donorMemberAddr) ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($staffName): ?>
                    <div class="card">
                        <h3>Staff Recipient</h3>
                        <div class="row">
                            <div class="label">Staff</div>
                            <div class="val"><?= $staffName ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="foot">
                    This is a system-generated <?= strtolower($docLabel) ?> for your records.
                </div>
            </div>
            <?php
        }
        ?>
    </body>

    </html>
    <?php
    exit();
}

/* ------------------- Fallback ------------------- */ else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();