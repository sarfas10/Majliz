<?php
// mahal_profile.php - UPDATED VERSION with Advanced Fee Distribution
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

/* --- db connection (shared helper) --- */
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/advanced_income_helper.php';

// Get database connection
$db_result = get_db_connection();

// Check if connection was successful
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}

$conn = $db_result['conn'];

// Fetch logged-in mahal details for sidebar
$user_id = $_SESSION['user_id'];
$sql_mahal = "SELECT name, address, registration_no, email FROM register WHERE id = ?";
$stmt_mahal = $conn->prepare($sql_mahal);
$stmt_mahal->bind_param("i", $user_id);
$stmt_mahal->execute();
$result_mahal = $stmt_mahal->get_result();

if ($result_mahal->num_rows > 0) {
    $mahal = $result_mahal->fetch_assoc();
} else {
    echo "<script>alert('Unable to fetch mahal details. Please log in again.'); window.location.href='index.php';</script>";
    exit();
}
$stmt_mahal->close();

// Define logo path
$logo_path = "logo.jpeg";

$mahal_details = [];
$update_message = "";
$error = "";
$has_monthly_fee = false;
$first_transaction_date = null;

// Password change variables
$password_message = "";
$password_error = "";


// Payment details variables
$payment_details = [];
$payment_update_message = "";

try {
    /* --- detect if mahal_payment_details table exists and create if not --- */
    $tableCheck = $conn->query("SHOW TABLES LIKE 'mahal_payment_details'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        $createTableSql = "CREATE TABLE mahal_payment_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mahal_id INT NOT NULL UNIQUE,
            bank_name VARCHAR(100),
            account_holder VARCHAR(100),
            account_number VARCHAR(50),
            ifsc_code VARCHAR(20),
            upi_id VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
        )";
        if ($conn->query($createTableSql) === FALSE) {
            error_log("Error creating table: " . $conn->error);
        }
    }

    /* --- detect/create mahal_additional_dues table --- */
    $duesTableCheck = $conn->query("SHOW TABLES LIKE 'mahal_additional_dues'");
    if (!$duesTableCheck || $duesTableCheck->num_rows == 0) {
        $createDuesSql = "CREATE TABLE mahal_additional_dues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mahal_id INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
        )";
        if ($conn->query($createDuesSql) === FALSE) {
            error_log("Error creating mahal_additional_dues table: " . $conn->error);
        }
    }

    /* --- detect/create member_additional_dues table (per-member tracking) --- */
    $memDuesTableCheck = $conn->query("SHOW TABLES LIKE 'member_additional_dues'");
    if (!$memDuesTableCheck || $memDuesTableCheck->num_rows == 0) {
        $createMemDuesSql = "CREATE TABLE member_additional_dues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            due_id INT NOT NULL,
            member_id INT NOT NULL,
            member_type ENUM('regular','sahakari') NOT NULL DEFAULT 'regular',
            amount DECIMAL(10,2) NOT NULL,
            paid TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (due_id) REFERENCES mahal_additional_dues(id) ON DELETE CASCADE
        )";
        if ($conn->query($createMemDuesSql) === FALSE) {
            error_log("Error creating member_additional_dues table: " . $conn->error);
        }
    }

    /* --- create mahal public profile tables if needed --- */
    $conn->query("CREATE TABLE IF NOT EXISTS mahal_public_profile (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mahal_id INT NOT NULL UNIQUE,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        established_year VARCHAR(10),
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS mahal_gallery (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mahal_id INT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        caption VARCHAR(200),
        sort_order INT DEFAULT 0,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS mahal_committee (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mahal_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        role VARCHAR(100),
        phone VARCHAR(20),
        image_path VARCHAR(255),
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE
    )");


    $colCheck = $conn->query("SHOW COLUMNS FROM register LIKE 'monthly_fee'");
    $has_monthly_fee = ($colCheck && $colCheck->num_rows > 0);

    /* --- get first transaction date --- */
    $firstDateStmt = $conn->prepare("SELECT MIN(transaction_date) as first_date FROM transactions WHERE user_id = ?");
    if ($firstDateStmt !== false) {
        $firstDateStmt->bind_param("i", $user_id);
        $firstDateStmt->execute();
        $firstDateResult = $firstDateStmt->get_result();
        if ($firstDateResult && ($firstDateRow = $firstDateResult->fetch_assoc())) {
            $first_transaction_date = $firstDateRow['first_date'];
        }
        $firstDateStmt->close();
    }

    /* --- handle profile update --- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_profile'])) {
            // Get and sanitize form data
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $registration_no = trim($_POST['registration_no'] ?? '');

            // Basic validation
            if (empty($name) || empty($email) || empty($registration_no)) {
                $update_message = "Name, email, and registration number are required fields.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $update_message = "Please enter a valid email address.";
            } else {
                $email_conflict = false;
                if ($email !== $mahal['email']) {
                    // Check if email already exists for another user
                    $checkEmail = $conn->prepare("SELECT id FROM register WHERE email = ? AND id != ?");
                    $checkEmail->bind_param("si", $email, $user_id);
                    $checkEmail->execute();
                    $emailResult = $checkEmail->get_result();
                    if ($emailResult->num_rows > 0) {
                        $email_conflict = true;
                    }
                    $checkEmail->close();
                }

                // Normalize for comparison
                $reg_conflict = false;
                if (trim($registration_no) !== trim($mahal['registration_no'])) {
                    // Check if registration number already exists for another user
                    $checkRegNo = $conn->prepare("SELECT id FROM register WHERE registration_no = ? AND id != ?");
                    $checkRegNo->bind_param("si", $registration_no, $user_id);
                    $checkRegNo->execute();
                    $regNoResult = $checkRegNo->get_result();

                    if ($regNoResult->num_rows > 0) {
                        $reg_conflict = true;
                    }
                    $checkRegNo->close();
                }

                if ($email_conflict) {
                    $update_message = "This email is already registered with another account.";
                } elseif ($reg_conflict) {
                    $update_message = "This registration number is already in use by another account.";
                } else {
                    // Update profile
                    $sql = "UPDATE register SET name = ?, address = ?, phone = ?, email = ?, registration_no = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssi", $name, $address, $phone, $email, $registration_no, $user_id);


                    if ($stmt->execute()) {
                        $update_message = "Profile updated successfully!";
                        // Update session variable
                        $_SESSION['user_name'] = $name;

                        // Update Payment Details
                        $bank_name = trim($_POST['bank_name'] ?? '');
                        $account_holder = trim($_POST['account_holder'] ?? '');
                        $account_number = trim($_POST['account_number'] ?? '');
                        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
                        $upi_id = trim($_POST['upi_id'] ?? '');

                        // Check if payment details exist
                        $checkPayment = $conn->prepare("SELECT id FROM mahal_payment_details WHERE mahal_id = ?");
                        $checkPayment->bind_param("i", $user_id);
                        $checkPayment->execute();
                        $paymentResult = $checkPayment->get_result();

                        if ($paymentResult->num_rows > 0) {
                            // Update existing
                            $updatePayment = $conn->prepare("UPDATE mahal_payment_details SET bank_name = ?, account_holder = ?, account_number = ?, ifsc_code = ?, upi_id = ? WHERE mahal_id = ?");
                            $updatePayment->bind_param("sssssi", $bank_name, $account_holder, $account_number, $ifsc_code, $upi_id, $user_id);
                            $updatePayment->execute();
                            $updatePayment->close();
                        } else {
                            // Insert new
                            $insertPayment = $conn->prepare("INSERT INTO mahal_payment_details (mahal_id, bank_name, account_holder, account_number, ifsc_code, upi_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $insertPayment->bind_param("isssss", $user_id, $bank_name, $account_holder, $account_number, $ifsc_code, $upi_id);
                            $insertPayment->execute();
                            $insertPayment->close();
                        }
                        $checkPayment->close();

                    } else {
                        $update_message = "Error updating profile: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }

        /* --- handle password change --- */
        if (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Validate passwords
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $password_error = "All password fields are required.";
            } elseif ($new_password !== $confirm_password) {
                $password_error = "New passwords do not match.";
            } elseif (strlen($new_password) < 6) {
                $password_error = "New password must be at least 6 characters long.";
            } else {
                // Fetch current hashed password from database
                $passStmt = $conn->prepare("SELECT password FROM register WHERE id = ?");
                $passStmt->bind_param("i", $user_id);
                $passStmt->execute();
                $passResult = $passStmt->get_result();

                if ($passResult->num_rows > 0) {
                    $user = $passResult->fetch_assoc();

                    // Verify current password
                    if (password_verify($current_password, $user['password'])) {
                        // Hash new password and update
                        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $updatePassStmt = $conn->prepare("UPDATE register SET password = ? WHERE id = ?");
                        $updatePassStmt->bind_param("si", $new_hashed_password, $user_id);

                        if ($updatePassStmt->execute()) {
                            $password_message = "Password changed successfully!";
                        } else {
                            $password_error = "Error updating password: " . $updatePassStmt->error;
                        }
                        $updatePassStmt->close();
                    } else {
                        $password_error = "Current password is incorrect.";
                    }
                } else {
                    $password_error = "User not found.";
                }
                $passStmt->close();
            }
        }

        /* --- handle monthly fee update --- */
        if (isset($_POST['update_monthly_fee'])) {
            $monthly_fee_raw = $_POST['monthly_fee'] ?? '';
            if (is_numeric($monthly_fee_raw) && floatval($monthly_fee_raw) >= 0) {
                $monthly_fee = number_format((float) $monthly_fee_raw, 2, '.', '');

                if (!$has_monthly_fee) {
                    if ($conn->query("ALTER TABLE register ADD COLUMN monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00") === false) {
                        $update_message = "Error creating monthly_fee column: " . $conn->error;
                    } else {
                        $has_monthly_fee = true;
                    }
                }

                if ($has_monthly_fee) {
                    $upd = $conn->prepare("UPDATE register SET monthly_fee = ? WHERE id = ?");
                    if ($upd === false) {
                        $update_message = "Error preparing update: " . $conn->error;
                    } else {
                        $upd->bind_param("di", $monthly_fee, $user_id);
                        if ($upd->execute()) {
                            $update_message = "Monthly fee updated successfully!";
                        } else {
                            $update_message = "Error updating monthly fee: " . $upd->error;
                        }
                        $upd->close();
                    }
                }
            } else {
                $update_message = "Please enter a valid monthly fee amount.";
            }
        }

        /* --- handle delete (rollback) additional due --- */
        if (isset($_POST['rollback_due'])) {
            $due_id = (int) ($_POST['due_id'] ?? 0);
            if ($due_id <= 0) {
                $update_message = "Invalid due ID.";
            } else {
                // Verify the due belongs to this mahal
                $verifyStmt = $conn->prepare("SELECT id, amount FROM mahal_additional_dues WHERE id = ? AND mahal_id = ?");
                if ($verifyStmt) {
                    $verifyStmt->bind_param("ii", $due_id, $user_id);
                    $verifyStmt->execute();
                    $verifyResult = $verifyStmt->get_result();
                    if ($verifyResult->num_rows === 0) {
                        $update_message = "Due not found or access denied.";
                    } else {
                        $due_row = $verifyResult->fetch_assoc();
                        $due_amount = (float) $due_row['amount'];

                        // Reverse total_due for regular members who haven't paid this due
                        $revRegStmt = $conn->prepare(
                            "UPDATE members m
                             JOIN member_additional_dues mad ON mad.member_id = m.id
                             SET m.total_due = GREATEST(0, m.total_due - mad.amount)
                             WHERE mad.due_id = ? AND mad.member_type = 'regular' AND mad.paid = 0"
                        );
                        if ($revRegStmt) {
                            $revRegStmt->bind_param("i", $due_id);
                            $revRegStmt->execute();
                            $revRegStmt->close();
                        }

                        // Delete the master due (member_additional_dues cascade deletes via FK)
                        $delStmt = $conn->prepare("DELETE FROM mahal_additional_dues WHERE id = ? AND mahal_id = ?");
                        if ($delStmt) {
                            $delStmt->bind_param("ii", $due_id, $user_id);
                            if ($delStmt->execute()) {
                                $update_message = "Additional due deleted and member balances reversed successfully.";
                            } else {
                                $update_message = "Error deleting due: " . $delStmt->error;
                            }
                            $delStmt->close();
                        }
                    }
                    $verifyStmt->close();
                } else {
                    $update_message = "Error preparing statement: " . $conn->error;
                }
            }
        }

        /* --- handle add additional due --- */
        if (isset($_POST['add_additional_due'])) {
            $due_title = trim($_POST['due_title'] ?? '');
            $due_amount_raw = $_POST['due_amount'] ?? '';
            $due_description = trim($_POST['due_description'] ?? '');

            if (empty($due_title)) {
                $update_message = "Due title is required.";
            } elseif (!is_numeric($due_amount_raw) || floatval($due_amount_raw) <= 0) {
                $update_message = "Please enter a valid due amount greater than zero.";
            } else {
                $due_amount = number_format((float) $due_amount_raw, 2, '.', '');

                // 1. Insert the master due record
                $insStmt = $conn->prepare("INSERT INTO mahal_additional_dues (mahal_id, title, amount, description) VALUES (?, ?, ?, ?)");
                if ($insStmt) {
                    $insStmt->bind_param("isds", $user_id, $due_title, $due_amount, $due_description);
                    if ($insStmt->execute()) {
                        $new_due_id = $insStmt->insert_id;
                        $regulars_count = 0;

                        // 2. Distribute to all active regular members only (sahakari members excluded)
                        $memStatusExists = $conn->query("SHOW COLUMNS FROM members LIKE 'status'");
                        $memStatusWhere = ($memStatusExists && $memStatusExists->num_rows > 0) ? " AND status != 'terminate'" : "";
                        $regRes = $conn->query("SELECT id FROM members WHERE mahal_id = $user_id{$memStatusWhere}");
                        if ($regRes) {
                            $insMemStmt = $conn->prepare("INSERT INTO member_additional_dues (due_id, member_id, member_type, amount) VALUES (?, ?, 'regular', ?)");
                            $updMemStmt = $conn->prepare("UPDATE members SET total_due = total_due + ? WHERE id = ?");
                            while ($mrow = $regRes->fetch_assoc()) {
                                $mid = (int) $mrow['id'];
                                $insMemStmt->bind_param("iid", $new_due_id, $mid, $due_amount);
                                $insMemStmt->execute();
                                $updMemStmt->bind_param("di", $due_amount, $mid);
                                $updMemStmt->execute();
                                $regulars_count++;
                            }
                            $insMemStmt->close();
                            $updMemStmt->close();
                        }

                        $update_message = "Additional due added and assigned to $regulars_count regular members (sahakari members excluded).";
                    } else {
                        $update_message = "Error saving due: " . $insStmt->error;
                    }
                    $insStmt->close();
                } else {
                    $update_message = "Error preparing statement: " . $conn->error;
                }
            }
        }
    }

    /* --- fetch current mahal details (include monthly_fee if present) --- */
    // Fetch mahal details
    $mahal_details = [];
    if ($stmt = $conn->prepare("SELECT * FROM register WHERE id = ?")) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $mahal_details = $result->fetch_assoc();
        }
        $stmt->close();
    }

    // Fetch Payment Details
    $payment_details = [];
    if ($payStmt = $conn->prepare("SELECT * FROM mahal_payment_details WHERE mahal_id = ?")) {
        $payStmt->bind_param("i", $user_id);
        $payStmt->execute();
        $payRes = $payStmt->get_result();
        if ($payRes->num_rows > 0) {
            $payment_details = $payRes->fetch_assoc();
        }
        $payStmt->close();
    }

    // Fetch Registration Charge from Settings
    $reg_fee_query = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'registration_charge'");
    $registration_fee = ($reg_fee_query && $row = $reg_fee_query->fetch_assoc()) ? $row['setting_value'] : '0';

    // Fetch Active Subscription
    $active_subscription = null;
    $subSql = "SELECT s.*, p.title as plan_title, p.monthly_price 
               FROM subscriptions s 
               JOIN plans p ON s.plan_id = p.id 
               WHERE s.mahal_id = ? AND s.status = 'active' 
               ORDER BY s.end_date DESC LIMIT 1";
    if ($stmtSub = $conn->prepare($subSql)) {
        $stmtSub->bind_param("i", $user_id);
        $stmtSub->execute();
        $subRes = $stmtSub->get_result();
        if ($subRes->num_rows > 0) {
            $active_subscription = $subRes->fetch_assoc();
        }
        $stmtSub->close();
    }

    // Fetch Sponsored Mahals
    $sponsored_mahals = [];
    $sponSql = "SELECT r.id, r.name, r.registration_no, r.phone, r.email, r.created_at, r.status, 
                       s.end_date, p.title as plan_title 
                FROM register r 
                LEFT JOIN subscriptions s ON r.id = s.mahal_id AND s.status = 'active' 
                LEFT JOIN plans p ON s.plan_id = p.id 
                WHERE r.sponsored_by = ? 
                ORDER BY r.created_at DESC";
    if ($stmtSpon = $conn->prepare($sponSql)) {
        $stmtSpon->bind_param("i", $user_id);
        $stmtSpon->execute();
        $sponRes = $stmtSpon->get_result();
        while ($srow = $sponRes->fetch_assoc()) {
            $sponsored_mahals[] = $srow;
        }
        $stmtSpon->close();
    }

    // Fetch Additional Dues History
    $additional_dues = [];
    $duesSql = "SELECT id, title, amount, description, created_at FROM mahal_additional_dues WHERE mahal_id = ? ORDER BY created_at DESC";
    if ($stmtDues = $conn->prepare($duesSql)) {
        $stmtDues->bind_param("i", $user_id);
        $stmtDues->execute();
        $duesRes = $stmtDues->get_result();
        while ($drow = $duesRes->fetch_assoc()) {
            $additional_dues[] = $drow;
        }
        $stmtDues->close();
    }

    // Ensure monthly_fee key exists for UI
    if (!array_key_exists('monthly_fee', $mahal_details)) {
        $mahal_details['monthly_fee'] = 0.00;
    }

    // Fetch Available Plans for Renewal
    $available_plans = [];
    $planSql = "SELECT * FROM plans ORDER BY yearly_price ASC";
    $planResult = $conn->query($planSql);
    if ($planResult) {
        while ($p = $planResult->fetch_assoc()) {
            $available_plans[] = $p;
        }
    }

    $conn->close();
} catch (Exception $e) {
    error_log("mahal_profile error: " . $e->getMessage());
    if (empty($error)) {
        $error = $e->getMessage();
    }
}

/* --- initials for avatars --- */
$user_initials = "";
if (!empty($_SESSION['user_name'])) {
    $parts = preg_split('/\s+/', trim($_SESSION['user_name']));
    foreach ($parts as $p) {
        $user_initials .= strtoupper(substr($p, 0, 1));
        if (strlen($user_initials) >= 2)
            break;
    }
}
$profile_initials = "";
if (!empty($mahal_details['name'])) {
    $parts = preg_split('/\s+/', trim($mahal_details['name']));
    foreach ($parts as $p) {
        $profile_initials .= strtoupper(substr($p, 0, 1));
        if (strlen($profile_initials) >= 2)
            break;
    }
}


/* ------------------ Get Payment Details ------------------ */
function get_payment_details()
{
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        return ['error' => $db_result['error']];
    }

    $conn = $db_result['conn'];

    // Check if admin_settings table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_settings'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $conn->close();
        return [];
    }

    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM admin_settings");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $conn->close();

    return $settings;
}

// Get payment details for display
$paymentDetails = get_payment_details();
$hasPaymentDetails = !empty($paymentDetails) && !isset($paymentDetails['error']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mahal Profile - <?php echo htmlspecialchars($mahal['name']); ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
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
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        body.no-scroll {
            overflow: hidden;
        }

        #app {
            display: flex;
            min-height: 100vh;
        }

        /* ─────────────────────────────────────────────
       SIDEBAR • Modern Clean Design
       ───────────────────────────────────────────── */
        .sidebar {
            width: 288px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
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

        /* Top Row - Clean */
        .top-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
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

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
            font-size: 20px;
        }

        /* Container */
        .container {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            flex: 1;
        }

        /* Page Header - Clean */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 24px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            line-height: 1;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn.green {
            background: linear-gradient(135deg, var(--success), #219653);
            color: #fff;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn.green:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
            background: linear-gradient(135deg, #219653, #1e7e34);
        }

        .btn.blue {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .btn.blue:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.4);
            background: linear-gradient(135deg, var(--primary-dark), #2c3e50);
        }

        .btn.white {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            box-shadow: var(--shadow);
        }

        .btn.white:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .stats {
            font-size: 16px;
            color: var(--text-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stats span {
            color: var(--primary);
            font-weight: 800;
            font-size: 18px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.3s ease;
            border: 1px solid;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }

        /* Profile Card */
        .profile-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 32px;
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid var(--border);
        }

        @media (max-width: 640px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
        }

        .profile-avatar-large {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 36px;
            box-shadow: var(--shadow);
            flex-shrink: 0;
            border: 4px solid white;
        }

        .profile-info {
            flex: 1;
            text-align: left;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .profile-email {
            font-size: 15px;
            color: var(--text-light);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-active {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary);
            font-size: 16px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 640px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-item {
            background: var(--card-alt);
            padding: 20px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .detail-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 500;
            color: var(--text);
        }

        .detail-value.editable {
            cursor: pointer;
            position: relative;
            padding-right: 20px;
        }

        .detail-value.editable:hover {
            color: var(--primary);
        }

        .detail-value.editable::after {
            content: '✏️';
            position: absolute;
            right: 0;
            top: 0;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .detail-value.editable:hover::after {
            opacity: 1;
        }

        .detail-full {
            grid-column: 1 / -1;
        }

        /* Edit Form Styles */
        .edit-form {
            background: var(--card-alt);
            padding: 24px;
            border-radius: var(--radius-sm);
            margin-top: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }


        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group label i {
            color: var(--primary);
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: inherit;
            background: var(--card);
            color: var(--text);
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        /* Password Change Card */
        .password-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 32px;
        }

        .password-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .password-form {
                grid-template-columns: 1fr;
            }
        }

        /* Fee Card */
        .fee-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius);
            padding: 28px;
            color: #fff;
            box-shadow: var(--shadow);
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
        }

        .fee-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: rgba(255, 255, 255, 0.3);
        }

        .fee-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .fee-title {
            font-size: 18px;
            font-weight: 600;
            opacity: 0.95;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .fee-amount {
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: baseline;
            gap: 2px;
        }

        .fee-currency {
            font-size: 20px;
            opacity: 0.9;
        }

        .fee-form {
            display: flex;
            gap: 16px;
            align-items: flex-end;
        }

        @media (max-width: 640px) {
            .fee-form {
                flex-direction: column;
                align-items: stretch;
            }
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            opacity: 0.95;
        }

        .form-input {
            padding: 14px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.95);
            color: var(--text);
            transition: var(--transition);
        }

        .form-input:focus {
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
        }

        .btn-primary {
            background: #fff;
            color: var(--primary);
            font-weight: 600;
            padding: 14px 24px;
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
        }

        .btn-outline {
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-outline:hover {
            background: var(--card-alt);
        }

        .action-footer {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 20px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: var(--card);
            border-radius: var(--radius);
            padding: 32px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
            position: relative;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            padding: 4px;
            transition: var(--transition);
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            color: var(--error);
            background: rgba(231, 76, 60, 0.1);
            transform: rotate(90deg);
        }

        .password-strength {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            background: var(--error);
            transition: width 0.3s ease;
            border-radius: 3px;
        }

        .password-strength-bar.medium {
            background: var(--warning);
        }

        .password-strength-bar.strong {
            background: var(--success);
        }

        /* Plan Selection Styles */
        .plan-options {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .plan-option {
            flex: 1;
            min-width: 120px;
        }

        .plan-option input[type="radio"] {
            display: none;
        }

        .plan-option label {
            display: block;
            padding: 16px;
            background: var(--card-alt);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
        }

        .plan-option input[type="radio"]:checked+label {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .plan-option label:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .plan-select-wrapper {
            position: relative;
            margin-bottom: 24px;
        }

        .plan-select-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            z-index: 1;
        }

        .plan-select {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 15px;
            background: var(--card);
            color: var(--text);
            transition: var(--transition);
            appearance: none;
            cursor: pointer;
        }

        .plan-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        /* Total Display */
        .total-display {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: 24px;
            border-radius: var(--radius);
            color: white;
            margin-top: 32px;
        }

        .total-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .total-amount {
            font-size: 36px;
            font-weight: 800;
            display: flex;
            align-items: baseline;
            gap: 2px;
        }

        /* Sponsored Mahals Table */
        .sponsored-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 14px;
        }

        .sponsored-table th {
            background: var(--card-alt);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-light);
            border-bottom: 2px solid var(--border);
        }

        .sponsored-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .sponsored-table tr:hover {
            background: var(--card-alt);
        }

        /* Financial Reports Section */
        .reports-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 32px;
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .report-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
        }

        .tab-btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--card-alt);
            color: var(--text);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .tab-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(74, 111, 165, 0.1), transparent);
            transition: left 0.5s;
        }

        .tab-btn:hover::before {
            left: 100%;
        }

        .tab-btn:hover {
            background: var(--border);
            transform: translateY(-1px);
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
        }

        .date-selector {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-input {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--card);
            color: var(--text);
            transition: var(--transition);
        }

        .date-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .report-content {
            display: none;
        }

        .report-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .report-options {
            background: var(--card-alt);
            padding: 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .option-row {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            color: var(--text);
        }

        .deduction-input {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            width: 120px;
            font-size: 14px;
            background: var(--card);
            color: var(--text);
            transition: var(--transition);
        }

        .deduction-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .deduction-input:disabled {
            background: var(--bg);
            color: var(--text-light);
        }

        .report-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 20px;
        }

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
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
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .actions {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .page-header {
                gap: 12px;
            }

            .stats {
                width: 100%;
                text-align: left;
            }

            .btn {
                padding: 12px 20px;
                font-size: 13px;
            }

            .top-row {
                padding: 16px 20px;
            }

            .page-title {
                font-size: 20px;
            }

            .profile-card,
            .reports-card,
            .password-card {
                padding: 24px;
            }

            .action-footer {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .reports-header {
                flex-direction: column;
                align-items: stretch;
            }

            .date-selector {
                flex-direction: column;
                align-items: stretch;
            }

            .date-input {
                width: 100%;
            }

            .report-tabs {
                flex-direction: column;
            }

            .tab-btn {
                width: 100%;
            }

            .modal-content {
                width: 95%;
                padding: 24px;
            }

            .total-amount {
                font-size: 28px;
            }
        }

        /* Minimal Subscription Styles */
        .subscription-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .subscription-header i {
            color: var(--primary);
            font-size: 20px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(67, 97, 238, 0.1);
            border-radius: 10px;
        }

        .subscription-header h3 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
            margin: 0;
        }

        .no-plans-alert {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
        }

        .no-plans-alert i {
            font-size: 18px;
        }

        .no-plans-alert p {
            margin: 0;
            font-size: 14px;
            font-weight: 500;
        }

        /* Minimal Renewal Form */
        .renewal-form-minimal {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Billing Cycle - Minimal */
        .billing-cycle-minimal {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .cycle-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .cycle-label::before {
            content: '📅';
            font-size: 14px;
        }

        .radio-group {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 14px;
            color: var(--text);
            position: relative;
            padding: 8px 0;
        }

        .radio-label input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .radio-custom {
            width: 18px;
            height: 18px;
            border: 2px solid var(--border);
            border-radius: 50%;
            display: inline-block;
            position: relative;
            transition: all 0.2s ease;
        }

        .radio-label input[type="radio"]:checked+.radio-custom {
            border-color: var(--primary);
            background: var(--primary);
        }

        .radio-label input[type="radio"]:checked+.radio-custom::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
        }

        .radio-label input[type="radio"]:focus+.radio-custom {
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
        }

        .radio-text {
            font-weight: 500;
        }

        /* Plan Selection - Minimal */
        .plan-select-minimal {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .select-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .select-label::before {
            content: '📋';
            font-size: 14px;
        }

        .select-wrapper {
            position: relative;
        }

        .plan-select-clean {
            width: 100%;
            padding: 12px 16px;
            padding-right: 40px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: var(--card);
            color: var(--text);
            appearance: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .plan-select-clean:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
        }

        .select-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 14px;
            pointer-events: none;
        }

        /* Renewal Summary - Minimal */
        .renewal-summary {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .total-minimal {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .total-label {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }

        /* Header Styles */
        .page-header {
            background: var(--card);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
        }

        .header-left {
            flex: 1;
            min-width: 300px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .page-title i {
            color: var(--primary);
            font-size: 20px;
            background: rgba(67, 97, 238, 0.1);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .profile-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        .stat-item i {
            color: var(--primary);
            font-size: 12px;
        }

        .stat-value {
            color: var(--text);
            font-weight: 600;
            margin-left: 4px;
        }

        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
            align-items: flex-end;
            min-width: 300px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        /* Fee Update Card */
        .fee-update-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-sm);
            padding: 16px;
            color: white;
            width: 100%;
            min-width: 300px;
        }

        .fee-update-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
        }

        .fee-update-header i {
            font-size: 16px;
            opacity: 0.9;
        }

        .fee-update-form {
            width: 100%;
        }

        .fee-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .input-with-icon {
            flex: 1;
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text);
            font-size: 14px;
        }

        .input-with-icon input {
            width: 100%;
            padding: 12px 12px 12px 36px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            background: white;
            color: var(--text);
            transition: all 0.2s ease;
        }


        /* Ultra-minimal Payment Container - Small & Compact */
        .payment-container {
            margin: 10px 0;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 12px;
            width: auto;
            display: inline-block;
            max-width: 600px;
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dee2e6;
        }

        .payment-header h3 {
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .payment-amount {
            background: #4a6fa5;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 11px;
        }

        .payment-details {
            margin: 0;
        }

        .payment-detail-item {
            display: flex;
            padding: 3px 0;
            line-height: 1.2;
        }

        .payment-detail-label {
            color: #6c757d;
            font-weight: 500;
            min-width: 80px;
            font-size: 11px;
        }

        .payment-detail-value {
            color: #2c3e50;
            font-weight: 500;
            font-size: 11px;
            word-break: break-word;
        }

        /* QR Code - Minimal */
        .payment-qr {
            text-align: center;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid #dee2e6;
        }

        #renewalQrcode,
        #sponsoredQrcode {
            margin: 0 auto 3px;
            display: inline-block;
        }

        .qr-instructions {
            font-size: 10px;
            color: #6c757d;
            margin-top: 2px;
        }

        .qr-upi-id {
            font-family: monospace;
            font-size: 10px;
            color: #4a6fa5;
            word-break: break-all;
        }

        /* Payment Confirmation - Minimal */
        .payment-confirmation {
            background: #e8f4ff;
            border-radius: 4px;
            padding: 6px 8px;
            margin: 6px 0 0;
            border: 1px solid #b3d7ff;
        }

        .confirmation-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .confirmation-checkbox input {
            width: 14px;
            height: 14px;
            cursor: pointer;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .confirmation-label {
            flex: 1;
        }

        .confirmation-label label {
            font-size: 11px;
            color: #2c3e50;
            line-height: 1.3;
            cursor: pointer;
            font-weight: 500;
            margin: 0;
        }

        .amount-badge {
            background: #4a6fa5;
            color: white;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            margin: 0 2px;
        }

        /* Image QR Code */
        .payment-qr img {
            width: 80px;
            height: 80px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 3px;
            background: white;
        }

        /* For QR code generation */
        .payment-qr canvas {
            width: 80px !important;
            height: 80px !important;
        }

        /* No Payment Details */
        .no-payment-details {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 8px;
            color: #92400e;
            font-size: 11px;
            text-align: center;
        }

        .no-payment-details i {
            font-size: 12px;
            margin-bottom: 3px;
            display: block;
        }

        /* Even more compact */
        .payment-detail-item:last-child {
            margin-bottom: 0;
        }

        .payment-container>*:last-child {
            margin-bottom: 0;
        }

        .input-with-icon input:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.3);
        }

        .btn-save-fee {
            background: white;
            color: var(--primary);
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-save-fee:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }

        .btn-save-fee i {
            font-size: 12px;
        }

        /* Button Styles */
        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn.green {
            background: linear-gradient(135deg, var(--success), #0da271);
            color: white;
        }

        .btn.green:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn.blue {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn.blue:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }

        .btn.white {
            background: white;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn.white:hover {
            background: var(--card-alt);
            transform: translateY(-1px);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .header-top {
                flex-direction: column;
                gap: 20px;
            }

            .header-left,
            .header-actions {
                width: 100%;
            }

            .action-buttons {
                justify-content: flex-start;
            }

            .header-actions {
                align-items: stretch;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }

            .profile-stats {
                flex-direction: column;
                gap: 12px;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .fee-input-group {
                flex-direction: column;
            }

            .btn-save-fee {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 16px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-title i {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
        }

        /* Validation styles - only for sponsor form */
        #sponsorForm input:focus,
        #sponsorForm textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(74, 111, 165, 0.2);
        }

        /* Success state */
        #sponsorForm input.valid,
        #sponsorForm textarea.valid {
            border-color: var(--success);
        }

        /* Error state */
        #sponsorForm input.error,
        #sponsorForm textarea.error {
            border-color: var(--error);
            border-width: 2px;
        }

        /* Validation error messages */
        .validation-error {
            font-size: 12px !important;
            margin-top: 4px !important;
            display: block !important;
            min-height: 16px !important;
            line-height: 1.3 !important;
        }

        .validation-error:empty {
            display: none !important;
        }

        /* Make form inputs show validation on the spot */
        #sponsorForm .form-input,
        #sponsorForm .form-textarea {
            transition: border-color 0.2s ease;
        }

        .total-amount-minimal {
            display: flex;
            align-items: baseline;
            gap: 2px;
            font-weight: 700;
        }

        .total-amount-minimal .currency {
            font-size: 16px;
            color: var(--primary);
        }

        .total-amount-minimal #totalDisplay {
            font-size: 24px;
            color: var(--text);
            font-weight: 700;
        }

        /* Submit Button - Minimal */
        .btn-renew-minimal {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s ease;
            width: 100%;
        }

        .btn-renew-minimal:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.2);
        }

        .btn-renew-minimal:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-renew-minimal i {
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .radio-group {
                flex-direction: column;
                gap: 12px;
            }

            .total-minimal {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .total-amount-minimal #totalDisplay {
                font-size: 20px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 12px;
            }

            .btn {
                font-size: 12.5px;
                padding: 10px 16px;
            }

            .profile-avatar-large {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }

            .profile-name {
                font-size: 20px;
            }

            .modal-content {
                padding: 20px;
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
                <!-- Profile with Logo - Clickable to Dashboard -->
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
                    <button class="menu-btn" type="button" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Admin Panel</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='finance-tracking.php'">
                        <i class="fas fa-chart-line"></i>
                        <span>Finance Tracking</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='member-management.php'">
                        <i class="fas fa-users"></i>
                        <span>Member Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='staff-management.php'">
                        <i class="fas fa-user-tie"></i>
                        <span>Staff Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='    asset_management.php'">
                        <i class="fas fa-boxes"></i>
                        <span>Asset Management</span>
                    </button>


                    <button class="menu-btn" type="button" onclick="window.location.href='academics.php'">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Academics</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='certificate.php'">
                        <i class="fas fa-certificate"></i>
                        <span>Certificate Management</span>
                    </button>

                    <button class="menu-btn" type="button" onclick="window.location.href='offerings.php'">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Offerings</span>
                    </button>

                    <button class="menu-btn active" type="button">
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

        <!-- Main Content -->
        <main class="main" id="main">
            <!-- Top Bar -->
            <section class="top-row">
                <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
                    aria-label="Open menu" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <i class="fas fa-building"></i>
                    Mahal Profile
                </div>
            </section>

            <div class="container">
                <!-- Sponsor Modal -->
                <div id="sponsorModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title"><i class="fas fa-hand-holding-heart"></i> Sponsor New Mahal</h3>
                            <button class="modal-close" onclick="closeSponsorModal()">&times;</button>
                        </div>
                        <div class="modal-body" style="padding: 24px;">
                            <form id="sponsorForm">
                                <div class="form-group">
                                    <label><i class="fas fa-building"></i> Mahal Name</label>
                                    <input type="text" name="name" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-map-marker-alt"></i> Address</label>
                                    <textarea name="address" class="form-textarea" required></textarea>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-envelope"></i> Email</label>
                                        <input type="email" name="email" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-phone"></i> Phone</label>
                                        <input type="tel" name="phone" class="form-input" required pattern="[0-9]{10}"
                                            title="10 digit phone number">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-id-card"></i> Registration No</label>
                                        <input type="text" name="registration_no" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-lock"></i> Password</label>
                                        <input type="password" name="password" class="form-input" required
                                            pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[@$!%?&]).{8,}"
                                            title="Must contain at least one number, one uppercase, lowercase letter, and special character, and at least 8 or more characters">
                                    </div>
                                </div>

                                <!-- Payment Section -->
                                <div class="fee-card" style="margin-top: 24px;">
                                    <h4 style="margin-bottom: 16px; font-size: 16px;"><i class="fas fa-credit-card"></i>
                                        Payment Details</h4>

                                    <div class="form-group">
                                        <label>Sponsorship Amount</label>
                                        <div
                                            style="display: flex; align-items: center; background: rgba(255,255,255,0.2); border-radius: 8px; padding: 12px;">
                                            <span
                                                style="font-size: 24px; font-weight: bold; margin-right: 8px;">₹</span>
                                            <input type="number" name="amount" class="form-input"
                                                style="border:none; background: transparent; color: white; font-size: 24px; font-weight: bold; width: 100%;"
                                                placeholder="0.00"
                                                value="<?php echo htmlspecialchars($registration_fee); ?>" readonly
                                                required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Payment Mode</label>
                                        <select name="payment_mode" class="form-input" style="color: #333;">
                                            <option value="CASH">CASH</option>
                                            <option value="G PAY">G PAY</option>
                                            <option value="BANK TRANSFER">BANK TRANSFER</option>
                                        </select>
                                    </div>

                                    <div style="margin-top: 20px; display: flex; align-items: center; gap: 12px;">
                                        <input type="checkbox" name="payment_confirmed" id="payConfirm" value="1"
                                            required style="width: 20px; height: 20px;">
                                        <label for="payConfirm" style="font-size: 14px; cursor: pointer;">I confirm that
                                            I have collected/paid the above amount.</label>
                                    </div>
                                </div>

                                <div class="action-footer" style="margin-top: 24px;">
                                    <button type="button" class="btn white" onclick="closeSponsorModal()">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                    <button type="submit" class="btn blue" id="sponsorSubmitBtn">
                                        <i class="fas fa-check-circle"></i> Register & Pay
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Page Header -->


                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($update_message)): ?>
                    <div
                        class="alert <?php echo (strpos($update_message, 'Error') !== false) ? 'alert-error' : 'alert-success'; ?>">
                        <i
                            class="fas <?php echo (strpos($update_message, 'Error') !== false) ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                        <?php echo htmlspecialchars($update_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($password_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($password_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($password_error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($password_error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($mahal_details)): ?>
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-header"
                            style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;">

                            <!-- Left Side: Profile Info -->
                            <div style="flex: 1;">
                                <div class="profile-info">
                                    <h2 class="profile-name"><?php echo htmlspecialchars($mahal_details['name'] ?? ''); ?>
                                    </h2>
                                    <div class="profile-email">
                                        <i
                                            class="fas fa-envelope"></i><?php echo htmlspecialchars($mahal_details['email'] ?? ''); ?>
                                        <div class="stat-item" style="margin-top: 8px;">
                                            <i class="fas fa-money-bill-wave"></i>
                                            Monthly Fee:
                                            <span
                                                class="stat-value">₹<?php echo number_format((float) ($mahal_details['monthly_fee'] ?? 0), 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="status-badge <?php echo 'status-' . htmlspecialchars($mahal_details['status'] ?? 'active'); ?>"
                                        style="margin-top: 8px;">
                                        <i class="fas fa-circle" style="font-size:6px;"></i>
                                        <?php echo htmlspecialchars(ucfirst($mahal_details['status'] ?? 'active')); ?>
                                    </div>
                                </div>
                            </div>


                            <!-- Right Side: Action Buttons -->
                            <div style="min-width: 500px; max-width: 700px;">
                                <!-- Horizontal Action Buttons -->
                                <div class="action-buttons" style="display: flex; gap: 10px; margin-bottom: 20px;">
                                    <button class="btn green" onclick="openEditModal()"
                                        style="flex: 1; justify-content: center; padding: 12px 16px; font-size: 14px; min-height: 50px;">
                                        <i class="fas fa-edit"></i>
                                        Edit Profile
                                    </button>
                                    <button class="btn blue" onclick="openPasswordModal()"
                                        style="flex: 1; justify-content: center; padding: 12px 16px; font-size: 14px; min-height: 50px;">
                                        <i class="fas fa-key"></i>
                                        Change Password
                                    </button>
                                    <button class="btn white" onclick="window.print()" style="flex: 1; justify-content: center; padding: 12px 16px; font-size: 14px; min-height: 50px; 
                               border: 1px solid var(--border);">
                                        <i class="fas fa-print"></i>
                                        Print Profile
                                    </button>
                                </div>
                                <!-- Fee Update Form -->
                                <div class="fee-update-card" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); 
                                               border-radius: var(--radius-sm); padding: 12px; color: white;">
                                    <div class="fee-update-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; 
                                                     font-size: 13px; font-weight: 600;">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <span>Update Monthly Fee</span>
                                    </div>
                                    <form method="POST" action="" class="fee-update-form">
                                        <div class="fee-input-group" style="display: flex; gap: 8px; align-items: center;">
                                            <div class="input-with-icon" style="flex: 1; position: relative;">
                                                <i class="fas fa-rupee-sign" style="position: absolute; left: 10px; top: 50%; 
                                                               transform: translateY(-50%); color: var(--text); 
                                                               font-size: 13px;"></i>
                                                <input type="number" name="monthly_fee"
                                                    value="<?php echo htmlspecialchars((string) ($mahal_details['monthly_fee'] ?? '0')); ?>"
                                                    placeholder="Enter amount" min="0" step="0.01" required style="width: 100%; padding: 8px 8px 8px 28px; border: none; border-radius: 6px; 
                                          font-size: 13px; font-weight: 500; background: white; color: var(--text);">
                                            </div>
                                            <button type="submit" name="update_monthly_fee" style="background: white; color: var(--primary); border: none; padding: 8px 12px; 
                                       border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; 
                                       display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                                <i class="fas fa-save" style="font-size: 11px;"></i>
                                                Update
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                        </div>

                        <h3 class="section-title"><i class="fas fa-info-circle"></i> Basic Information</h3>

                        <div class="details-grid">
                            <div class="detail-item">
                                <div class="detail-label"><i class="fas fa-id-card"></i> Registration No.</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($mahal_details['registration_no'] ?? ''); ?>
                                    <div class="detail-label"><i class="fas fa-calendar-plus"></i> Member Since</div>
                                    <div class="detail-value">
                                        <?php echo !empty($mahal_details['created_at']) ? date('F j, Y', strtotime($mahal_details['created_at'])) : '-'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label"><i class=""></i> </div>
                                <div class="detail-value">
                                    <div class="detail-label"><i class="fas fa-certificate"></i> Subscription Plan</div>
                                    <div class="detail-value">
                                        <?php if ($active_subscription): ?>
                                            <span style="color: var(--success); font-weight: bold;">
                                                <?php echo htmlspecialchars($active_subscription['plan_title']); ?>
                                            </span>
                                            <div style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                <i class="far fa-calendar"></i> Exp:
                                                <?php echo date('d M Y', strtotime($active_subscription['end_date'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-light);">No active plan</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label"><i class="fas fa-user-tag"></i> Account Role</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars(ucfirst($mahal_details['role'] ?? '')); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label"><i class="fas fa-phone"></i> Phone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($mahal_details['phone'] ?? ''); ?>
                                </div>
                            </div>
                            <div class="detail-item detail-full">
                                <div class="detail-label"><i class="fas fa-map-marker-alt"></i> Full Address</div>
                                <div class="detail-value"><?php echo htmlspecialchars($mahal_details['address'] ?? ''); ?>
                                </div>
                            </div>

                            <!-- Payment Details Section in Profile Card -->
                            <div class="detail-item detail-full"
                                style="background: #f8fafc; border: 1px dashed var(--border);">
                                <div class="detail-label"><i class="fas fa-university"></i> Payment Details</div>
                                <div class="detail-value">
                                    <div
                                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 8px;">
                                        <?php if (!empty($payment_details)): ?>
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light); display: block;">Bank
                                                    Name</span>
                                                <span
                                                    style="font-weight: 500; font-size: 13px;"><?php echo htmlspecialchars($payment_details['bank_name'] ?? '-'); ?></span>
                                            </div>
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light); display: block;">Account
                                                    Holder</span>
                                                <span
                                                    style="font-weight: 500; font-size: 13px;"><?php echo htmlspecialchars($payment_details['account_holder'] ?? '-'); ?></span>
                                            </div>
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light); display: block;">Account
                                                    Number</span>
                                                <span
                                                    style="font-weight: 500; font-size: 13px; font-family: monospace;"><?php echo htmlspecialchars($payment_details['account_number'] ?? '-'); ?></span>
                                            </div>
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light); display: block;">IFSC
                                                    Code</span>
                                                <span
                                                    style="font-weight: 500; font-size: 13px; font-family: monospace;"><?php echo htmlspecialchars($payment_details['ifsc_code'] ?? '-'); ?></span>
                                            </div>
                                            <div>
                                                <span style="font-size: 11px; color: var(--text-light); display: block;">UPI
                                                    ID</span>
                                                <span
                                                    style="font-weight: 500; font-size: 13px; color: var(--primary);"><?php echo htmlspecialchars($payment_details['upi_id'] ?? '-'); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div
                                                style="grid-column: 1 / -1; color: var(--text-light); font-style: italic; font-size: 13px;">
                                                No payment details added. Click "Edit Profile" to add bank details.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Renew Subscription Card -->
                    <!-- Renew Subscription Card -->
                    <!-- Renew Subscription Button -->
                    <div class="profile-card">
                        <h3 class="section-title"><i class="fas fa-sync-alt"></i> Subscription Management</h3>

                        <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 20px;">
                            <!-- Current Plan Info -->
                            <div style="flex: 1; min-width: 200px;">
                                <div
                                    style="background: var(--card-alt); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <i class="fas fa-crown" style="color: var(--primary); font-size: 18px;"></i>
                                        <div>
                                            <div style="font-size: 14px; color: var(--text-light);">Current Plan</div>
                                            <div style="font-size: 16px; font-weight: 600; color: var(--text);">
                                                <?php if ($active_subscription): ?>
                                                    <?php echo htmlspecialchars($active_subscription['plan_title']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">No active plan</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($active_subscription): ?>
                                        <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                            <div>
                                                <i class="far fa-calendar"></i>
                                                Expires:
                                                <?php echo date('d M Y', strtotime($active_subscription['end_date'])); ?>
                                            </div>
                                            <div>
                                                <?php
                                                $days_left = ceil((strtotime($active_subscription['end_date']) - time()) / (60 * 60 * 24));
                                                if ($days_left > 0) {
                                                    echo "<span style='color: var(--success);'>$days_left days left</span>";
                                                } else {
                                                    echo "<span style='color: var(--error);'>Expired</span>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Renew Button -->
                            <div style="flex-shrink: 0;">
                                <button class="btn green" onclick="openRenewModal()"
                                    style="padding: 14px 28px; font-size: 15px; min-width: 160px;">
                                    <i class="fas fa-sync-alt"></i> Renew Plan
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Renew Subscription Modal -->
                    <div class="modal" id="renewModal">
                        <div class="modal-content" style="max-width: 600px;">
                            <div class="modal-header">
                                <h3 class="modal-title"><i class="fas fa-sync-alt"></i> Renew Your Subscription</h3>
                                <button class="modal-close" onclick="closeRenewModal()">&times;</button>
                            </div>
                            <div style="padding: 24px;">
                                <!-- Current Plan Info -->
                                <div
                                    style="background: var(--card-alt); border-radius: var(--radius-sm); padding: 20px; margin-bottom: 24px; border: 1px solid var(--border);">
                                    <h4
                                        style="margin-bottom: 12px; font-size: 16px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-info-circle" style="color: var(--primary);"></i> Current
                                        Subscription
                                    </h4>
                                    <div id="currentPlanInfo">
                                        <?php if ($active_subscription): ?>
                                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                                                <div>
                                                    <div style="font-size: 12px; color: var(--text-light);">Plan Name</div>
                                                    <div style="font-weight: 600;">
                                                        <?php echo htmlspecialchars($active_subscription['plan_title']); ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div style="font-size: 12px; color: var(--text-light);">Expiry Date</div>
                                                    <div style="font-weight: 600;">
                                                        <?php echo date('d M Y', strtotime($active_subscription['end_date'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div style="text-align: center; padding: 20px;">
                                                <i class="fas fa-exclamation-circle"
                                                    style="font-size: 32px; color: var(--text-light); margin-bottom: 12px;"></i>
                                                <p style="color: var(--text-light);">No active subscription found</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($available_plans)): ?>
                                    <form id="renewalModalForm" onsubmit="event.preventDefault(); submitRenewalFromModal();">
                                        <!-- Billing Cycle -->
                                        <div class="form-group">
                                            <label
                                                style="display:block; margin-bottom:12px; font-weight: 600; color: var(--text);">Billing
                                                Cycle</label>
                                            <div class="plan-options">
                                                <div class="plan-option">
                                                    <input type="radio" name="duration_type" value="year" checked
                                                        id="modal_yearly" onchange="updateModalPlanPrices()">
                                                    <label for="modal_yearly">Yearly</label>
                                                </div>
                                                <div class="plan-option">
                                                    <input type="radio" name="duration_type" value="month" id="modal_monthly"
                                                        onchange="updateModalPlanPrices()">
                                                    <label for="modal_monthly">Monthly</label>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Plan Selection -->
                                        <div class="plan-select-wrapper">
                                            <i class="fas fa-cube"></i>
                                            <select id="modal_plan_select" name="plan_id" class="plan-select"
                                                onchange="calculateModalTotal()" required>
                                                <option value="" data-yearly="0" data-monthly="0">-- Choose a Plan --</option>
                                                <?php foreach ($available_plans as $p): ?>
                                                    <option value="<?= $p['id'] ?>" data-yearly="<?= $p['yearly_price'] ?>"
                                                        data-monthly="<?= $p['monthly_price'] ?>">
                                                        <?= htmlspecialchars($p['title']) ?>
                                                        (₹<?= number_format($p['yearly_price'], 2) ?>/year)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Payment Details Section -->
                                        <?php if ($hasPaymentDetails): ?>
                                            <div class="payment-container" style="margin-top: 20px; margin-bottom: 20px;">
                                                <div class="payment-header"
                                                    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                                                    <h3 style="font-size: 16px; font-weight: 600; color: var(--text);">
                                                        <i class="fas fa-credit-card"></i> Payment Details
                                                    </h3>
                                                    <div class="payment-amount"
                                                        style="background: rgba(74, 111, 165, 0.1); padding: 8px 12px; border-radius: 6px;">
                                                        <i class="fas fa-rupee-sign"></i>
                                                        <span id="modalAmountDisplay">0.00</span>
                                                    </div>
                                                </div>

                                                <div class="payment-details"
                                                    style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px;">
                                                    <?php if (!empty($paymentDetails['bank_name'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">Bank Name</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_name']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($paymentDetails['bank_account_name'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">Account Holder</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_account_name']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($paymentDetails['bank_account_number'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">Account Number</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_account_number']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($paymentDetails['bank_ifsc'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">IFSC Code</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_ifsc']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($paymentDetails['upi_id'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">UPI ID</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['upi_id']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($paymentDetails['admin_phone'])): ?>
                                                        <div class="payment-detail-item">
                                                            <span class="payment-detail-label">Contact Phone</span>
                                                            <span
                                                                class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['admin_phone']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Dynamic QR Code for Modal -->
                                                <?php
                                                if (!empty($paymentDetails['upi_id'])):
                                                    $upi_pa = $paymentDetails['upi_id'];
                                                    $upi_pn = "Subscription Renewal";
                                                    $upi_am = '0';

                                                    echo "<div id='modal-upi-data' 
                                            data-pa='" . htmlspecialchars($upi_pa) . "' 
                                            data-pn='" . htmlspecialchars($upi_pn) . "' 
                                            data-am='" . htmlspecialchars($upi_am) . "' 
                                            style='display:none;'></div>";
                                                    ?>
                                                    <div class="payment-qr"
                                                        style="text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                                                        <div id="modalQrcode"
                                                            style="display: flex; justify-content: center; margin-bottom: 8px;"></div>
                                                        <p style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                                            Scan QR to Pay <br>
                                                            <small><?php echo htmlspecialchars($upi_pa); ?></small>
                                                        </p>
                                                    </div>
                                                <?php elseif (!empty($paymentDetails['qr_code_path'])): ?>
                                                    <!-- Fallback to static image -->
                                                    <div class="payment-qr"
                                                        style="text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                                                        <img src="<?php echo htmlspecialchars($paymentDetails['qr_code_path']); ?>"
                                                            alt="Payment QR Code" onerror="this.style.display='none'"
                                                            style="width: 120px; height: 120px; border: 1px solid var(--border); border-radius: 6px; padding: 6px; background: white;">
                                                        <p style="font-size: 12px; color: var(--text-light); margin-top: 4px;">Scan QR
                                                            to Pay</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Payment Confirmation Checkbox -->
                                            <div class="payment-confirmation"
                                                style="background: linear-gradient(135deg, #e8f4ff 0%, #d4e7ff 100%); border-radius: 8px; padding: 16px; margin-bottom: 20px; border: 2px solid var(--primary-light);">
                                                <div class="confirmation-checkbox"
                                                    style="display: flex; align-items: flex-start; gap: 12px;">
                                                    <input type="checkbox" id="modal_payment_confirmed" name="payment_confirmed"
                                                        value="1"
                                                        style="width: 20px; height: 20px; margin-top: 3px; cursor: pointer;">
                                                    <div class="confirmation-label" style="flex: 1;">
                                                        <label for="modal_payment_confirmed"
                                                            style="font-size: 14px; color: var(--text); line-height: 1.5; cursor: pointer; display: block; font-weight: 500;">
                                                            I confirm that I have <strong>completed the payment</strong> of
                                                            <span class="amount-badge"
                                                                style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: 5px; font-weight: 600; font-size: 12px; margin: 0 3px;">
                                                                <i class="fas fa-rupee-sign"></i>
                                                                <span id="modalConfirmationAmount">0.00</span>
                                                            </span>
                                                            via the provided payment methods.
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="total-display" style="margin-top: 20px;">
                                            <div class="total-label">Total Amount</div>
                                            <div class="total-amount">
                                                <span style="font-size: 20px; opacity: 0.9;">₹</span>
                                                <span id="modalTotalDisplay">0.00</span>
                                            </div>
                                            <button type="submit" class="btn btn-primary" id="modalRenewBtn" disabled
                                                style="margin-top: 20px; width: 100%;">
                                                <i class="fas fa-arrow-right"></i> Submit Renewal Request
                                            </button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="no-plans-alert">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <p>No subscription plans available at the moment. Please contact support.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Sponsorship Section -->
                    <div class="profile-card">
                        <h3 class="section-title"><i class="fas fa-hand-holding-heart"></i>Sponsorship</h3>
                        <p style="color: var(--text-light); margin-bottom: 20px; font-size: 15px; line-height: 1.6;">
                            Sponsor a new Mahal by registering them and paying their initial fees.
                        </p>
                        <button class="btn blue" onclick="openSponsorModal()">
                            <i class="fas fa-plus-circle"></i> Sponsor New Mahal
                        </button>

                        <!-- Sponsored List -->
                        <div id="sponsoredList" style="margin-top: 32px;">
                            <?php if (!empty($sponsored_mahals)): ?>
                                <h4 style="margin-bottom: 16px; font-size: 16px; font-weight: 600; color: var(--text);">
                                    Sponsored Mahals (<?php echo count($sponsored_mahals); ?>)
                                </h4>
                                <div style="overflow-x: auto;">
                                    <table class="sponsored-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Reg No.</th>
                                                <th>Plan</th>
                                                <th>Expiry</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sponsored_mahals as $sm): ?>
                                                <tr>
                                                    <td style="font-weight: 600;">
                                                        <?php echo htmlspecialchars($sm['name']); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($sm['registration_no']); ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($sm['plan_title'])): ?>
                                                            <span style="color: var(--primary); font-weight: 500;">
                                                                <?php echo htmlspecialchars($sm['plan_title']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-light);">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($sm['end_date'])): ?>
                                                            <span
                                                                style="<?php echo (strtotime($sm['end_date']) < time()) ? 'color: var(--error);' : ''; ?>">
                                                                <?php echo date('d M Y', strtotime($sm['end_date'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-light);">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span
                                                            class="status-badge status-<?php echo htmlspecialchars($sm['status']); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                            <?php echo ucfirst(htmlspecialchars($sm['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn white" style="padding: 6px 12px; font-size: 12px;"
                                                            onclick='openSponsoredRenewalModal(<?php echo json_encode($sm); ?>)'>
                                                            <i class="fas fa-sync-alt"></i> Renew
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div
                                    style="background: var(--card-alt); padding: 24px; border-radius: var(--radius-sm); text-align: center; color: var(--text-light); margin-top: 20px;">
                                    <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                    <p style="font-size: 15px;">You haven't sponsored any Mahals yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>


                    <!-- Additional Dues Section -->
                    <div class="profile-card" id="additionalDuesCard">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                            <h3 class="section-title" style="margin: 0;"><i class="fas fa-file-invoice-dollar"></i>
                                Additional Dues</h3>
                            <button class="btn blue" onclick="toggleDuesForm()" id="toggleDuesBtn"
                                style="padding: 10px 18px; font-size: 13px;">
                                <i class="fas fa-plus"></i> Add New Due
                            </button>
                        </div>

                        <!-- Add Due Form (hidden by default) -->
                        <div id="addDueFormWrapper"
                            style="display: none; background: var(--card-alt); border-radius: var(--radius-sm); padding: 20px; margin-bottom: 24px; border: 1px solid var(--border);">
                            <h4
                                style="margin-bottom: 16px; font-size: 15px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-plus-circle" style="color: var(--primary);"></i> New Additional Due
                            </h4>
                            <form method="POST" action="">
                                <div class="form-row"
                                    style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                                    <div class="form-group" style="margin: 0;">
                                        <label
                                            style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text-light);"><i
                                                class="fas fa-tag"></i> Due Title <span
                                                style="color:var(--error);">*</span></label>
                                        <input type="text" name="due_title" class="form-input"
                                            placeholder="e.g. Eid Celebration Fund" required
                                            style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: white;">
                                    </div>
                                    <div class="form-group" style="margin: 0;">
                                        <label
                                            style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text-light);"><i
                                                class="fas fa-rupee-sign"></i> Amount (₹) <span
                                                style="color:var(--error);">*</span></label>
                                        <input type="number" name="due_amount" class="form-input" placeholder="0.00"
                                            min="0.01" step="0.01" required
                                            style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: white;">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label
                                        style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text-light);"><i
                                            class="fas fa-align-left"></i> Description (Optional)</label>
                                    <textarea name="due_description" class="form-input" rows="3"
                                        placeholder="Brief description about this due..."
                                        style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: white; resize: vertical;"></textarea>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="add_additional_due" class="btn green"
                                        style="padding: 12px 24px;">
                                        <i class="fas fa-save"></i> Save Due
                                    </button>
                                    <button type="button" onclick="toggleDuesForm()" class="btn white"
                                        style="padding: 12px 24px; border: 1px solid var(--border);">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Dues History Table -->
                        <?php if (!empty($additional_dues)): ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                    <thead>
                                        <tr
                                            style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white;">
                                            <th
                                                style="padding: 12px 16px; text-align: left; font-weight: 600; border-radius: 8px 0 0 0;">
                                                #</th>
                                            <th style="padding: 12px 16px; text-align: left; font-weight: 600;"><i
                                                    class="fas fa-calendar-alt"></i> Date</th>
                                            <th style="padding: 12px 16px; text-align: left; font-weight: 600;"><i
                                                    class="fas fa-tag"></i> Title</th>
                                            <th style="padding: 12px 16px; text-align: right; font-weight: 600;"><i
                                                    class="fas fa-rupee-sign"></i> Amount</th>
                                            <th style="padding: 12px 16px; text-align: left; font-weight: 600;">
                                                <i class="fas fa-align-left"></i> Description
                                            </th>
                                            <th
                                                style="padding: 12px 16px; text-align: center; font-weight: 600; border-radius: 0 8px 0 0;">
                                                <i class="fas fa-trash-alt"></i> Action
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($additional_dues as $idx => $due): ?>
                                            <tr style="border-bottom: 1px solid var(--border); transition: background 0.2s;"
                                                onmouseover="this.style.background='var(--card-alt)'"
                                                onmouseout="this.style.background='transparent'">
                                                <td style="padding: 12px 16px; color: var(--text-light); font-weight: 600;">
                                                    <?php echo $idx + 1; ?>
                                                </td>
                                                <td style="padding: 12px 16px; color: var(--text-light); white-space: nowrap;">
                                                    <?php echo date('d M Y', strtotime($due['created_at'])); ?>
                                                </td>
                                                <td style="padding: 12px 16px; font-weight: 600; color: var(--text);">
                                                    <?php echo htmlspecialchars($due['title']); ?>
                                                </td>
                                                <td
                                                    style="padding: 12px 16px; text-align: right; font-weight: 700; color: var(--primary); white-space: nowrap;">
                                                    ₹<?php echo number_format((float) $due['amount'], 2); ?></td>
                                                <td style="padding: 12px 16px; color: var(--text-light); font-size: 13px;">
                                                    <?php echo !empty($due['description']) ? htmlspecialchars($due['description']) : '<span style="font-style:italic;">—</span>'; ?>
                                                </td>
                                                <td style="padding: 10px 16px; text-align: center;">
                                                    <form method="POST" action="" style="display:inline;"
                                                        onsubmit="return confirm('Delete this due? The amount will be reversed from all members\' balances and this entry will be permanently removed.')">
                                                        <input type="hidden" name="due_id" value="<?php echo (int) $due['id']; ?>">
                                                        <button type="submit" name="rollback_due"
                                                            title="Delete &amp; reverse member balances"
                                                            style="background:linear-gradient(135deg,#ef4444,#dc2626);color:white;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s;"
                                                            onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 12px rgba(239,68,68,0.4)';"
                                                            onmouseout="this.style.transform='';this.style.boxShadow='';">
                                                            <i class="fas fa-trash-alt"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background: var(--card-alt); font-weight: 700;">
                                            <td colspan="4" style="padding: 12px 16px; text-align: right; color: var(--text);">
                                                Total:</td>
                                            <td
                                                style="padding: 12px 16px; text-align: right; color: var(--primary); font-size: 15px;">
                                                ₹<?php echo number_format(array_sum(array_column($additional_dues, 'amount')), 2); ?>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div
                                style="background: var(--card-alt); border-radius: var(--radius-sm); padding: 32px; text-align: center; color: var(--text-light);">
                                <i class="fas fa-file-invoice-dollar"
                                    style="font-size: 40px; margin-bottom: 12px; opacity: 0.4;"></i>
                                <p style="font-size: 15px; margin: 0;">No additional dues have been added yet.</p>
                                <p style="font-size: 13px; margin-top: 6px;">Click <strong>"Add New Due"</strong> above to
                                    create the first entry.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Financial Reports Section -->
                    <div class="reports-card">
                        <div class="reports-header">
                            <h3 class="section-title" style="margin:0"><i class="fas fa-chart-line"></i>Financial Reports
                            </h3>
                            <div class="date-selector">
                                <label style="font-size:14px; font-weight:600; color: var(--text);">From:</label>
                                <input type="date" id="dateFrom" class="date-input"
                                    min="<?php echo $first_transaction_date ? htmlspecialchars($first_transaction_date) : date('Y-m-d'); ?>"
                                    value="<?php echo $first_transaction_date ? htmlspecialchars($first_transaction_date) : date('Y-m-d'); ?>">
                                <label style="font-size:14px; font-weight:600; color: var(--text);">To:</label>
                                <input type="date" id="dateTo" class="date-input" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="report-tabs">
                            <button class="tab-btn active" onclick="switchTab(event, 'regular')">
                                <i class="fas fa-file-invoice"></i> Regular Report
                            </button>
                            <button class="tab-btn" onclick="switchTab(event, 'waqf')">
                                <i class="fas fa-file-alt"></i> Waqf Board Report
                            </button>
                        </div>

                        <!-- Regular Report -->
                        <div id="regularReport" class="report-content active">
                            <div class="report-actions">
                                <button class="btn blue" onclick="printRegularReport()">
                                    <i class="fas fa-print"></i> Print Regular Report
                                </button>
                            </div>
                        </div>

                        <!-- Waqf Board Report -->
                        <div id="waqfReport" class="report-content">
                            <div class="report-options">
                                <div class="option-row">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="applyDeduction" onchange="toggleDeduction()">
                                        <label for="applyDeduction">Apply Deduction to Income</label>
                                    </div>
                                    <input type="number" id="deductionPercent" class="deduction-input" placeholder="%"
                                        min="0" max="100" step="0.01" disabled>
                                </div>
                                <div class="option-row">
                                    <small style="color:var(--text-light); font-size:13px;">
                                        <i class="fas fa-info-circle"></i> Note: Deduction is applied only to income
                                        amounts, not to opening balance or
                                        expenses.
                                    </small>
                                </div>
                            </div>
                            <div class="report-actions">
                                <button class="btn blue" onclick="printWaqfReport()">
                                    <i class="fas fa-print"></i> Print Waqf Report
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════
                         PUBLIC PROFILE MANAGEMENT CARD
                    ═══════════════════════════════════════════════ -->
                    <div class="profile-card" style="margin-top:20px;">
                        <div class="card-header">
                            <h3 class="section-title"><i class="fas fa-globe"></i> Public Profile Page</h3>
                        </div>
                        <div id="promoLoadingMsg" style="padding:24px;text-align:center;color:var(--text-light);"><i
                                class="fas fa-spinner fa-spin"></i> Loading...</div>
                        <div id="promoContent" style="display:none;">

                            <!-- Slug & Description -->
                            <div style="padding:20px 24px 0;">
                                <div class="section-title"
                                    style="font-size:14px;margin-bottom:16px;color:var(--text-light);">Configure your public
                                    mahal page URL &amp; description</div>

                                <!-- Slug row -->
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label class="form-label"><i class="fas fa-link"></i> Page URL Slug <small
                                            style="color:var(--text-light);">(letters, numbers, hyphens
                                            only)</small></label>
                                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                        <input type="text" id="promoSlug" class="form-input"
                                            placeholder="e.g. VandanpathalJM" style="max-width:300px;"
                                            pattern="[a-zA-Z0-9_\-]+" oninput="updatePromoPreview()">
                                        <a id="promoPreviewLink" href="#" target="_blank"
                                            style="font-size:13px;color:var(--accent);text-decoration:none;white-space:nowrap;">
                                            <i class="fas fa-external-link-alt"></i> Preview page
                                        </a>
                                    </div>
                                    <small id="promoSlugHint"
                                        style="color:var(--text-light);font-size:12px;margin-top:6px;display:block;"></small>
                                </div>

                                <!-- Description -->
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label class="form-label"><i class="fas fa-align-left"></i> Short Description</label>
                                    <textarea id="promoDesc" class="form-input" rows="4"
                                        placeholder="Write a brief introduction about your mahal..."
                                        style="resize:vertical;"></textarea>
                                </div>

                                <!-- Established year -->
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label class="form-label"><i class="fas fa-calendar"></i> Established Year <small
                                            style="color:var(--text-light);">(optional)</small></label>
                                    <input type="text" id="promoEstYear" class="form-input" placeholder="e.g. 1985"
                                        style="max-width:180px;" maxlength="10">
                                </div>

                                <!-- Published toggle -->
                                <div class="form-group" style="margin-bottom:20px;">
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                        <input type="checkbox" id="promoPublished"
                                            style="width:18px;height:18px;cursor:pointer;">
                                        <span><i class="fas fa-eye"></i> Page is publicly visible</span>
                                    </label>
                                </div>

                                <button class="btn blue" onclick="savePromoProfile()" id="savePromoBtn">
                                    <i class="fas fa-save"></i> Save Profile Settings
                                </button>
                                <span id="promoSaveMsg" style="margin-left:12px;font-size:13px;"></span>
                            </div>

                            <!-- ── GALLERY ─────────────────────────────────────────── -->
                            <div style="padding:24px;border-top:1px solid var(--border);margin-top:24px;">
                                <h4 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fas fa-images"></i>
                                    Photo Gallery</h4>
                                <p style="font-size:13px;color:var(--text-light);margin-bottom:16px;">Upload photos to
                                    display in the public gallery. Max 5 MB each. JPEG/PNG/WebP supported.</p>

                                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                                    <input type="file" id="galleryFileInput" accept="image/*" multiple style="display:none;"
                                        onchange="uploadGalleryImages(this)">
                                    <button class="btn white" onclick="document.getElementById('galleryFileInput').click()">
                                        <i class="fas fa-upload"></i> Upload Photos
                                    </button>
                                    <span id="galleryUploadMsg" style="font-size:13px;color:var(--text-light);"></span>
                                </div>

                                <!-- Gallery thumbs -->
                                <div id="galleryGrid"
                                    style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;">
                                </div>
                            </div>

                            <!-- ── COMMITTEE ───────────────────────────────────────── -->
                            <div style="padding:24px;border-top:1px solid var(--border);">
                                <h4 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fas fa-users"></i>
                                    Committee Members</h4>

                                <!-- Add member form -->
                                <div
                                    style="background:var(--card-alt);border-radius:var(--radius-sm);padding:20px;margin-bottom:20px;">
                                    <h5
                                        style="margin-bottom:16px;font-size:13px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:0.5px;">
                                        Add New Member</h5>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                                        <div>
                                            <label class="form-label" style="font-size:12px;">Name *</label>
                                            <input type="text" id="cmName" class="form-input" placeholder="Full name">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size:12px;">Role / Position</label>
                                            <input type="text" id="cmRole" class="form-input" placeholder="e.g. President">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size:12px;">Phone Number</label>
                                            <input type="text" id="cmPhone" class="form-input"
                                                placeholder="+91 XXXXX XXXXX">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size:12px;">Photo <small
                                                    style="color:var(--text-light);">(optional)</small></label>
                                            <input type="file" id="cmPhoto" accept="image/*" class="form-input"
                                                style="padding:6px;">
                                        </div>
                                    </div>
                                    <button class="btn blue" onclick="addCommitteeMember()" id="addCmBtn">
                                        <i class="fas fa-plus"></i> Add Member
                                    </button>
                                    <span id="cmMsg" style="margin-left:12px;font-size:13px;"></span>
                                </div>

                                <!-- Existing members list -->
                                <div id="committeeList"></div>
                            </div>

                        </div><!-- end #promoContent -->
                    </div><!-- end public profile card -->

                <?php else: ?>

                    <div class="profile-card">
                        <div style="text-align:center; padding:40px;">
                            <i class="fas fa-exclamation-triangle"
                                style="font-size:48px; color:var(--accent); margin-bottom:20px;"></i>
                            <h3 style="font-size:18px; margin-bottom:10px;">Profile Not Found</h3>
                            <p style="font-size:14px; color:var(--text-light);">Unable to load mahal profile details.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Sponsored Renewal Modal -->
    <!-- Sponsored Renewal Modal -->
    <div class="modal" id="sponsoredRenewalModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-sync-alt"></i> Renew Sponsored Mahal</h3>
                <button class="modal-close" onclick="closeSponsoredRenewalModal()">&times;</button>
            </div>
            <div
                style="margin-bottom: 20px; padding: 16px; background: var(--card-alt); border-radius: var(--radius-sm);">
                <strong>Mahal:</strong> <span id="sponReqName"></span><br>
                <small style="color: var(--text-light);"><i class="fas fa-id-card"></i> Reg No: <span
                        id="sponReqReg"></span></small>
            </div>
            <form id="sponsoredRenewalForm" onsubmit="event.preventDefault(); submitSponsoredRenewal();">
                <input type="hidden" id="sponReqId" name="target_mahal_id">

                <div class="form-group">
                    <label style="display:block; margin-bottom:12px; font-weight: 600; color: var(--text);">Billing
                        Cycle</label>
                    <div class="plan-options">
                        <div class="plan-option">
                            <input type="radio" name="duration_type" value="year" checked id="spon_yearly"
                                onchange="updateSponPlanPrices()">
                            <label for="spon_yearly">Yearly</label>
                        </div>
                        <div class="plan-option">
                            <input type="radio" name="duration_type" value="month" id="spon_monthly"
                                onchange="updateSponPlanPrices()">
                            <label for="spon_monthly">Monthly</label>
                        </div>
                    </div>
                </div>

                <div class="plan-select-wrapper">
                    <i class="fas fa-cube"></i>
                    <select id="spon_plan_select" name="plan_id" class="plan-select" onchange="calculateSponTotal()"
                        required>
                        <option value="" data-yearly="0" data-monthly="0">-- Choose a Plan --</option>
                        <?php foreach ($available_plans as $p): ?>
                            <option value="<?= $p['id'] ?>" data-yearly="<?= $p['yearly_price'] ?>"
                                data-monthly="<?= $p['monthly_price'] ?>">
                                <?= htmlspecialchars($p['title']) ?> (₹<?= number_format($p['yearly_price'], 2) ?>/year)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Payment Details for Sponsored Renewal -->
                <?php if ($hasPaymentDetails): ?>
                    <div class="payment-container" style="margin-top: 20px; margin-bottom: 20px;">
                        <div class="payment-header"
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: var(--text);">
                                <i class="fas fa-credit-card"></i> Payment Details
                            </h3>
                            <div class="payment-amount"
                                style="background: rgba(74, 111, 165, 0.1); padding: 8px 12px; border-radius: 6px;">
                                <i class="fas fa-rupee-sign"></i>
                                <span id="sponsoredAmountDisplay">0.00</span>
                            </div>
                        </div>

                        <div class="payment-details"
                            style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px;">
                            <?php if (!empty($paymentDetails['bank_name'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">Bank Name</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_name']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentDetails['bank_account_name'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">Account Holder</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_account_name']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentDetails['bank_account_number'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">Account Number</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_account_number']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentDetails['bank_ifsc'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">IFSC Code</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['bank_ifsc']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentDetails['upi_id'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">UPI ID</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['upi_id']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentDetails['admin_phone'])): ?>
                                <div class="payment-detail-item">
                                    <span class="payment-detail-label">Contact Phone</span>
                                    <span
                                        class="payment-detail-value"><?php echo htmlspecialchars($paymentDetails['admin_phone']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Dynamic QR Code for Sponsored Renewal -->
                        <?php
                        if (!empty($paymentDetails['upi_id'])):
                            $upi_pa = $paymentDetails['upi_id'];
                            $upi_pn = "Sponsored Renewal";
                            $upi_am = '0';

                            echo "<div id='sponsored-upi-data' 
                                    data-pa='" . htmlspecialchars($upi_pa) . "' 
                                    data-pn='" . htmlspecialchars($upi_pn) . "' 
                                    data-am='" . htmlspecialchars($upi_am) . "' 
                                    style='display:none;'></div>";
                            ?>
                            <div class="payment-qr"
                                style="text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                                <div id="sponsoredQrcode" style="display: flex; justify-content: center; margin-bottom: 8px;">
                                </div>
                                <p style="font-size: 12px; color: var(--text-light); margin-top: 4px;">
                                    Scan QR to Pay <br>
                                    <small><?php echo htmlspecialchars($upi_pa); ?></small>
                                </p>
                            </div>
                        <?php elseif (!empty($paymentDetails['qr_code_path'])): ?>
                            <!-- Fallback to static image -->
                            <div class="payment-qr"
                                style="text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                                <img src="<?php echo htmlspecialchars($paymentDetails['qr_code_path']); ?>"
                                    alt="Payment QR Code" onerror="this.style.display='none'"
                                    style="width: 120px; height: 120px; border: 1px solid var(--border); border-radius: 6px; padding: 6px; background: white;">
                                <p style="font-size: 12px; color: var(--text-light); margin-top: 4px;">Scan QR to Pay</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Payment Confirmation Checkbox -->
                    <div class="payment-confirmation"
                        style="background: linear-gradient(135deg, #e8f4ff 0%, #d4e7ff 100%); border-radius: 8px; padding: 16px; margin-bottom: 20px; border: 2px solid var(--primary-light);">
                        <div class="confirmation-checkbox" style="display: flex; align-items: flex-start; gap: 12px;">
                            <input type="checkbox" id="sponsored_payment_confirmed" name="payment_confirmed" value="1"
                                style="width: 20px; height: 20px; margin-top: 3px; cursor: pointer;">
                            <div class="confirmation-label" style="flex: 1;">
                                <label for="sponsored_payment_confirmed"
                                    style="font-size: 14px; color: var(--text); line-height: 1.5; cursor: pointer; display: block; font-weight: 500;">
                                    I confirm that I have <strong>completed the payment</strong> of
                                    <span class="amount-badge"
                                        style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: 5px; font-weight: 600; font-size: 12px; margin: 0 3px;">
                                        <i class="fas fa-rupee-sign"></i>
                                        <span id="sponsoredConfirmationAmount">0.00</span>
                                    </span>
                                    via the provided payment methods.
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="total-display" style="margin-top: 20px;">
                    <div class="total-label">Total Amount</div>
                    <div class="total-amount">
                        <span style="font-size: 20px; opacity: 0.9;">₹</span>
                        <span id="sponTotalDisplay">0.00</span>
                    </div>
                    <button type="submit" class="btn btn-primary" id="sponRenewBtn" disabled
                        style="margin-top: 20px; width: 100%;">
                        <i class="fas fa-arrow-right"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Profile Information</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="" id="editProfileForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name"><i class="fas fa-building"></i> Organization Name *</label>
                        <input type="text" id="name" name="name"
                            value="<?php echo htmlspecialchars($mahal_details['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="email" name="email"
                            value="<?php echo htmlspecialchars($mahal_details['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="registration_no"><i class="fas fa-id-card"></i> Registration Number *</label>
                        <input type="text" id="registration_no" name="registration_no"
                            value="<?php echo htmlspecialchars($mahal_details['registration_no'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" id="phone" name="phone"
                            value="<?php echo htmlspecialchars($mahal_details['phone'] ?? ''); ?>">
                    </div>
                    <label for="address"><i class="fas fa-map-marker-alt"></i> Full Address</label>
                    <textarea id="address" name="address"
                        rows="3"><?php echo htmlspecialchars($mahal_details['address'] ?? ''); ?></textarea>
                </div>

                <!-- Payment Details Fields -->
                <div class="form-group full-width"
                    style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border);">
                    <label style="color: var(--primary); margin-bottom: 15px;"><i class="fas fa-university"></i> Payment
                        Details</label>
                </div>

                <div class="form-group">
                    <label for="bank_name">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name"
                        value="<?php echo htmlspecialchars($payment_details['bank_name'] ?? ''); ?>"
                        placeholder="Bank Name">
                </div>
                <div class="form-group">
                    <label for="account_holder">Account Holder Name</label>
                    <input type="text" id="account_holder" name="account_holder"
                        value="<?php echo htmlspecialchars($payment_details['account_holder'] ?? ''); ?>"
                        placeholder="Account Holder Name">
                </div>
                <div class="form-group">
                    <label for="account_number">Account Number</label>
                    <input type="text" id="account_number" name="account_number"
                        value="<?php echo htmlspecialchars($payment_details['account_number'] ?? ''); ?>"
                        placeholder="Account Number">
                </div>
                <div class="form-group">
                    <label for="ifsc_code">IFSC Code</label>
                    <input type="text" id="ifsc_code" name="ifsc_code"
                        value="<?php echo htmlspecialchars($payment_details['ifsc_code'] ?? ''); ?>"
                        placeholder="IFSC Code" style="text-transform: uppercase;">
                </div>
                <div class="form-group full-width">
                    <label for="upi_id"><i class="fas fa-mobile-alt"></i> UPI ID (for QR Code)</label>
                    <input type="text" id="upi_id" name="upi_id"
                        value="<?php echo htmlspecialchars($payment_details['upi_id'] ?? ''); ?>"
                        placeholder="username@bank">
                </div>
        </div>
        <div class="action-footer">
            <button type="button" class="btn white" onclick="closeEditModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" name="update_profile" class="btn green">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
        </form>
    </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-key"></i> Change Password</h3>
                <button class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <form method="POST" action="" id="changePasswordForm">
                <div class="form-group">
                    <label for="current_password"><i class="fas fa-lock"></i> Current Password *</label>
                    <input type="password" id="current_password" name="current_password" required
                        placeholder="Enter your current password">
                </div>
                <div class="form-group">
                    <label for="new_password"><i class="fas fa-key"></i> New Password *</label>
                    <input type="password" id="new_password" name="new_password" required
                        placeholder="Enter new password" onkeyup="checkPasswordStrength()">
                    <div class="password-strength">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                    <small style="font-size: 12px; color: var(--text-light); margin-top: 4px; display: block;">
                        <i class="fas fa-info-circle"></i> Password must be at least 6 characters long.
                    </small>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-check-circle"></i> Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                        placeholder="Confirm new password" onkeyup="checkPasswordMatch()">
                    <small id="passwordMatch" style="font-size: 12px; display: block; margin-top: 4px;"></small>
                </div>
                <div class="action-footer">
                    <button type="button" class="btn white" onclick="closePasswordModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="change_password" class="btn blue">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Subscription Renewal Logic
        // Update plan prices based on billing cycle
        // Update plan prices based on billing cycle
        function updatePlanPrices() {
            const select = document.getElementById('plan_select');
            if (!select) return;
            const cycleElement = document.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const cycle = cycleElement.value;
            const options = select.querySelectorAll('option');

            options.forEach(opt => {
                if (opt.value) {
                    const price = cycle === 'month' ? opt.dataset.monthly : opt.dataset.yearly;
                    const text = cycle === 'month' ? '/month' : '/year';
                    opt.textContent = opt.textContent.replace(/ - ₹.*?\//, ` - ₹${parseFloat(price).toLocaleString('en-IN', { minimumFractionDigits: 2 })}/`);
                }
            });
            calculateTotal();
        }

        // Calculate total amount
        function calculateTotal() {
            const select = document.getElementById('plan_select');
            if (!select) return;
            const cycleElement = document.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const cycle = cycleElement.value;
            const selectedOption = select.options[select.selectedIndex];

            let price = 0;
            if (selectedOption.value) {
                price = parseFloat(cycle === 'month' ? selectedOption.getAttribute('data-monthly') : selectedOption.getAttribute('data-yearly'));
            }

            const renewBtn = document.getElementById('renewBtn');
            const totalDisplay = document.getElementById('totalDisplay');

            if (price > 0) {
                renewBtn.disabled = false;
            } else {
                renewBtn.disabled = true;
            }

            totalDisplay.textContent = price.toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Submit renewal
        function submitRenewal() {
            const form = document.getElementById('renewalForm');
            const formData = new FormData(form);
            const btn = document.getElementById('renewBtn');
            const originalText = btn.innerHTML;

            // Validate
            if (!formData.get('plan_id')) {
                showNotification('Please select a plan', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Processing...';

            fetch('submit_subscription_request.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Renewal request submitted successfully!', 'success');
                        form.reset();
                        updatePlanPrices();
                        calculateTotal();
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An unexpected error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function () {
            updatePlanPrices();
        });
        let currentOpeningBalance = 0;

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

        // Modal functionality
        const editModal = document.getElementById('editModal');
        const passwordModal = document.getElementById('passwordModal');

        function openEditModal() {
            editModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            editModal.style.display = 'none';
            document.body.style.overflow = '';
        }

        function openPasswordModal() {
            passwordModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closePasswordModal() {
            passwordModal.style.display = 'none';
            document.body.style.overflow = '';
            document.getElementById('changePasswordForm').reset();
            document.getElementById('passwordStrengthBar').style.width = '0%';
            document.getElementById('passwordStrengthBar').className = 'password-strength-bar';
            document.getElementById('passwordMatch').innerHTML = '';
            document.getElementById('passwordMatch').style.color = '';
        }

        // Close modals when clicking outside
        window.onclick = function (event) {
            if (event.target == editModal) closeEditModal();
            if (event.target == passwordModal) closePasswordModal();
            if (event.target == sponsorModal) closeSponsorModal();
            if (event.target == sponModal) closeSponsoredRenewalModal();
        }

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('passwordStrengthBar');

            let strength = 0;

            if (password.length >= 6) strength += 25;
            if (password.length >= 8) strength += 25;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 25;
            if (/\d/.test(password)) strength += 15;
            if (/[^A-Za-z0-9]/.test(password)) strength += 10;

            strengthBar.style.width = strength + '%';

            if (strength < 50) {
                strengthBar.className = 'password-strength-bar';
            } else if (strength < 75) {
                strengthBar.className = 'password-strength-bar medium';
            } else {
                strengthBar.className = 'password-strength-bar strong';
            }
        }

        // Password match checker
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchElement = document.getElementById('passwordMatch');

            if (confirmPassword === '') {
                matchElement.innerHTML = '';
                return;
            }

            if (newPassword === confirmPassword) {
                matchElement.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Passwords match';
                matchElement.style.color = 'var(--success)';
            } else {
                matchElement.innerHTML = '<i class="fas fa-times-circle" style="color: var(--error);"></i> Passwords do not match';
                matchElement.style.color = 'var(--error)';
            }
        }

        // Form validation
        document.getElementById('changePasswordForm').addEventListener('submit', function (e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                showNotification('Passwords do not match. Please make sure both password fields are identical.', 'error');
                return false;
            }

            if (newPassword.length < 6) {
                e.preventDefault();
                showNotification('Password must be at least 6 characters long.', 'error');
                return false;
            }

            return true;
        });

        // Profile form validation
        document.getElementById('editProfileForm').addEventListener('submit', function (e) {
            const registrationNo = document.getElementById('registration_no').value;
            const email = document.getElementById('email').value;

            if (!registrationNo.trim()) {
                e.preventDefault();
                showNotification('Registration number is required.', 'error');
                return false;
            }

            if (!email.trim()) {
                e.preventDefault();
                showNotification('Email address is required.', 'error');
                return false;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showNotification('Please enter a valid email address.', 'error');
                return false;
            }

            return true;
        });

        // Profile functionality
        document.addEventListener('DOMContentLoaded', function () {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease';
            setTimeout(function () { document.body.style.opacity = '1'; }, 100);

            const df = document.getElementById('dateFrom');
            const dt = document.getElementById('dateTo');
            if (df) df.addEventListener('change', updateOpeningBalance);
            if (dt) dt.addEventListener('change', updateOpeningBalance);

            updateOpeningBalance();
            updatePlanPrices();
        });

        // Notification system
        function showNotification(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'error' : 'warning'}`;
            alert.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle'}"></i>
                ${message}
            `;

            const container = document.querySelector('.container');
            container.insertBefore(alert, container.firstChild);

            setTimeout(() => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        }

        // Update opening balance for the report period
        function updateOpeningBalance() {
            const dateFromEl = document.getElementById('dateFrom');
            if (!dateFromEl) return;
            const dateFrom = dateFromEl.value;
            if (!dateFrom) return;

            const url = 'get_opening.php?date=' + encodeURIComponent(dateFrom);
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        currentOpeningBalance = data.opening_balance || 0;
                    } else {
                        currentOpeningBalance = 0;
                    }
                })
                .catch(error => {
                    console.error('Error fetching opening balance:', error);
                    currentOpeningBalance = 0;
                });
        }

        function switchTab(ev, tab) {
            if (!ev) return;
            const tabBtns = document.querySelectorAll('.tab-btn');
            tabBtns.forEach(btn => btn.classList.remove('active'));
            const clicked = ev.currentTarget || ev.target;
            const btnEl = clicked.closest ? clicked.closest('.tab-btn') : clicked;
            if (btnEl) btnEl.classList.add('active');

            document.getElementById('regularReport').classList.remove('active');
            document.getElementById('waqfReport').classList.remove('active');

            if (tab === 'regular') {
                document.getElementById('regularReport').classList.add('active');
            } else {
                document.getElementById('waqfReport').classList.add('active');
            }
        }

        function toggleDeduction() {
            const checkbox = document.getElementById('applyDeduction');
            const input = document.getElementById('deductionPercent');
            input.disabled = !checkbox.checked;
            if (!checkbox.checked) {
                input.value = '';
            } else {
                input.focus();
            }
        }

        function printRegularReport() {
            const dateFrom = document.getElementById('dateFrom') ? document.getElementById('dateFrom').value : '';
            const dateTo = document.getElementById('dateTo') ? document.getElementById('dateTo').value : '';

            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }

            if (currentOpeningBalance === undefined) {
                showNotification('Please wait for opening balance to load', 'error');
                return;
            }

            const url = 'print_regular_report.php?from=' + encodeURIComponent(dateFrom) +
                '&to=' + encodeURIComponent(dateTo) +
                '&opening=' + encodeURIComponent(currentOpeningBalance);
            window.open(url, '_blank');
        }

        function printWaqfReport() {
            const dateFrom = document.getElementById('dateFrom') ? document.getElementById('dateFrom').value : '';
            const dateTo = document.getElementById('dateTo') ? document.getElementById('dateTo').value : '';
            const applyDeduction = document.getElementById('applyDeduction') ? document.getElementById('applyDeduction').checked : false;
            const deductionPercent = document.getElementById('deductionPercent') ? document.getElementById('deductionPercent').value : '';

            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }

            if (applyDeduction && (!deductionPercent || parseFloat(deductionPercent) <= 0)) {
                showNotification('Please enter a valid deduction percentage', 'error');
                return;
            }

            if (currentOpeningBalance === undefined) {
                showNotification('Please wait for opening balance to load', 'error');
                return;
            }

            let url = 'print_waqf_report.php?from=' + encodeURIComponent(dateFrom) +
                '&to=' + encodeURIComponent(dateTo) +
                '&opening=' + encodeURIComponent(currentOpeningBalance);
            if (applyDeduction) {
                url += '&deduction=' + encodeURIComponent(deductionPercent);
            }

            window.open(url, '_blank');
        }

        // --- Sponsored Mahal Renewal Logic ---
        const sponModal = document.getElementById('sponsoredRenewalModal');
        const sponsorModal = document.getElementById('sponsorModal');

        function openSponsoredRenewalModal(mahal) {
            document.getElementById('sponReqName').textContent = mahal.name;
            document.getElementById('sponReqReg').textContent = mahal.registration_no;
            document.getElementById('sponReqId').value = mahal.id;

            if (sponModal) {
                sponModal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                document.getElementById('sponsoredRenewalForm').reset();
                updateSponPlanPrices();
            }
        }

        function closeSponsoredRenewalModal() {
            if (sponModal) {
                sponModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        function openSponsorModal() {
            sponsorModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeSponsorModal() {
            sponsorModal.style.display = 'none';
            document.body.style.overflow = '';
        }

        function updateSponPlanPrices() {
            const form = document.getElementById('sponsoredRenewalForm');
            if (!form) return;
            const cycleElement = form.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const durationType = cycleElement.value;
            const select = document.getElementById('spon_plan_select');
            if (!select) return;
            const options = select.querySelectorAll('option');

            options.forEach(opt => {
                if (opt.value) {
                    const price = durationType === 'month' ? opt.dataset.monthly : opt.dataset.yearly;
                    const text = durationType === 'month' ? '/month' : '/year';
                    opt.textContent = opt.textContent.replace(/\(.*?\)/, `(₹${parseFloat(price).toLocaleString('en-IN', { minimumFractionDigits: 2 })}${text})`);
                }
            });
            calculateSponTotal();
        }

        function calculateSponTotal() {
            const select = document.getElementById('spon_plan_select');
            const form = document.getElementById('sponsoredRenewalForm');
            if (!select || !form) return;

            const cycleElement = form.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const durationType = cycleElement.value;

            const selectedOption = select.options[select.selectedIndex];
            let price = 0;

            if (selectedOption.value) {
                price = parseFloat(durationType === 'month' ? selectedOption.getAttribute('data-monthly') : selectedOption.getAttribute('data-yearly'));
            }

            const btn = document.getElementById('sponRenewBtn');
            const totalDisplay = document.getElementById('sponTotalDisplay');

            if (price > 0) {
                btn.disabled = false;
                btn.style.opacity = "1";
            } else {
                btn.disabled = true;
                btn.style.opacity = "0.6";
            }

            totalDisplay.textContent = price.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function submitSponsoredRenewal() {
            const form = document.getElementById('sponsoredRenewalForm');
            const formData = new FormData(form);
            const btn = document.getElementById('sponRenewBtn');
            const originalText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Submitting...';
            btn.style.opacity = "0.6";

            fetch('submit_subscription_request.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Success: ' + data.message, 'success');
                        closeSponsoredRenewalModal();
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An unexpected error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    calculateSponTotal();
                    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Submit Request';
                    btn.style.opacity = "1";
                });
        }

        // Sponsor Form Submission
        document.getElementById('sponsorForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const btn = document.getElementById('sponsorSubmitBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner"></span> Processing...';
            btn.disabled = true;
            btn.style.opacity = "0.6";

            const formData = new FormData(this);

            fetch('sponsor_mahal.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        closeSponsorModal();
                        this.reset();
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.style.opacity = "1";
                });
        });

        // Sponsor Form Inline Validation - Show errors only for invalid format, not for empty fields
        function setupSponsorFormValidation() {
            const form = document.getElementById('sponsorForm');
            if (!form) return;

            // Get form elements
            const nameInput = form.querySelector('input[name="name"]');
            const addressInput = form.querySelector('textarea[name="address"]');
            const emailInput = form.querySelector('input[name="email"]');
            const phoneInput = form.querySelector('input[name="phone"]');
            const regInput = form.querySelector('input[name="registration_no"]');
            const passwordInput = form.querySelector('input[name="password"]');

            // Create error display elements for all fields
            [nameInput, addressInput, emailInput, phoneInput, regInput, passwordInput].forEach(input => {
                if (!input) return;

                // Create error span
                const errorSpan = document.createElement('span');
                errorSpan.className = 'validation-error';
                errorSpan.style.cssText = `
            color: var(--error);
            font-size: 12px;
            margin-top: 4px;
            display: block;
            min-height: 16px;
            line-height: 1.3;
        `;

                // Insert after input
                if (input.parentNode) {
                    input.parentNode.appendChild(errorSpan);
                }

                // Add validation events - validate on input (as typing)
                input.addEventListener('input', function () {
                    // Clear previous styling
                    this.style.borderColor = '';
                    errorSpan.textContent = '';

                    // Only validate if there's content
                    if (this.value.trim() !== '') {
                        validateFieldOnInput(this);
                    }
                });

                // Also validate on blur (when leaving field)
                input.addEventListener('blur', function () {
                    if (this.value.trim() !== '') {
                        validateFieldOnInput(this);
                    }
                });
            });
        }

        // Validate field as user types (only when there's content)
        function validateFieldOnInput(input) {
            const value = input.value.trim();
            const errorSpan = input.parentNode.querySelector('.validation-error');

            if (!errorSpan) return;

            // Clear previous error
            errorSpan.textContent = '';
            input.style.borderColor = '';

            // Validate based on field type
            switch (input.name) {
                case 'name':
                    validateName(input, value, errorSpan);
                    break;
                case 'email':
                    validateEmail(input, value, errorSpan);
                    break;
                case 'phone':
                    validatePhone(input, value, errorSpan);
                    break;
                case 'password':
                    validatePassword(input, value, errorSpan);
                    break;
                case 'registration_no':
                    validateRegistrationNo(input, value, errorSpan);
                    break;
                case 'address':
                    // Address can have any characters
                    break;
            }
        }

        // Validate name - only letters and spaces
        function validateName(input, value, errorSpan) {
            // Name pattern: letters, spaces, apostrophes, hyphens
            const namePattern = /^[A-Za-z\s\-'.]+$/;

            if (!namePattern.test(value)) {
                errorSpan.textContent = 'Name can only contain letters, spaces, hyphens (-), apostrophes (\'), and dots (.)';
                input.style.borderColor = 'var(--error)';
                return false;
            }

            return true;
        }

        // Validate email format
        function validateEmail(input, value, errorSpan) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!emailPattern.test(value)) {
                errorSpan.textContent = 'Please enter a valid email address (example@domain.com)';
                input.style.borderColor = 'var(--error)';
                return false;
            }

            return true;
        }

        // Validate phone - exactly 10 digits
        function validatePhone(input, value, errorSpan) {
            const phonePattern = /^[0-9]{10}$/;

            if (!phonePattern.test(value)) {
                errorSpan.textContent = 'Phone number must be exactly 10 digits (numbers only)';
                input.style.borderColor = 'var(--error)';
                return false;
            }

            return true;
        }

        // Validate password with strength indicator
        function validatePassword(input, value, errorSpan) {
            let errors = [];

            // Check minimum length
            if (value.length < 8) {
                errors.push('At least 8 characters');
            }

            // Check for uppercase
            if (!/[A-Z]/.test(value)) {
                errors.push('One uppercase letter');
            }

            // Check for lowercase
            if (!/[a-z]/.test(value)) {
                errors.push('One lowercase letter');
            }

            // Check for number
            if (!/\d/.test(value)) {
                errors.push('One number');
            }

            // Check for special character
            if (!/[@$!%*?&]/.test(value)) {
                errors.push('One special character (@$!%*?&)');
            }

            if (errors.length > 0) {
                errorSpan.innerHTML = 'Password needs:<br>' + errors.join('<br>');
                input.style.borderColor = 'var(--error)';
                return false;
            }

            // If password is strong, show success message
            errorSpan.textContent = '✓ Strong password';
            errorSpan.style.color = 'var(--success)';
            input.style.borderColor = 'var(--success)';
            return true;
        }

        // Validate registration number (alphanumeric with optional dashes/underscores)
        function validateRegistrationNo(input, value, errorSpan) {
            const regPattern = /^[A-Za-z0-9\-_]+$/;

            if (!regPattern.test(value)) {
                errorSpan.textContent = 'Registration number can only contain letters, numbers, hyphens (-), and underscores (_)';
                input.style.borderColor = 'var(--error)';
                return false;
            }

            return true;
        }

        // Validate entire form before submission
        function validateSponsorForm() {
            const form = document.getElementById('sponsorForm');
            if (!form) return false;

            let isValid = true;
            const requiredFields = [
                'name', 'address', 'email', 'phone', 'registration_no', 'password'
            ];

            // First, check if all required fields are filled
            requiredFields.forEach(fieldName => {
                const input = form.querySelector(`[name="${fieldName}"]`);
                if (input && input.value.trim() === '') {
                    isValid = false;
                    const errorSpan = input.parentNode.querySelector('.validation-error');
                    if (errorSpan) {
                        errorSpan.textContent = 'This field is required';
                        errorSpan.style.color = 'var(--error)';
                        input.style.borderColor = 'var(--error)';
                    }
                }
            });

            if (!isValid) {
                showNotification('Please fill all required fields', 'error');
                return false;
            }

            // Now validate each field's format
            const fieldsToValidate = [
                { name: 'name', validator: validateName },
                { name: 'email', validator: validateEmail },
                { name: 'phone', validator: validatePhone },
                { name: 'password', validator: validatePassword },
                { name: 'registration_no', validator: validateRegistrationNo }
            ];

            fieldsToValidate.forEach(field => {
                const input = form.querySelector(`[name="${field.name}"]`);
                if (input && input.value.trim() !== '') {
                    const errorSpan = input.parentNode.querySelector('.validation-error');
                    if (!field.validator(input, input.value.trim(), errorSpan)) {
                        isValid = false;
                    }
                }
            });

            return isValid;
        }

        // Initialize validation when sponsor modal opens
        function setupSponsorModalValidation() {
            const sponsorModal = document.getElementById('sponsorModal');
            if (sponsorModal) {
                // Set up validation when modal opens
                const originalOpen = window.openSponsorModal;
                window.openSponsorModal = function () {
                    originalOpen();

                    // Reset all validation errors when modal opens
                    const form = document.getElementById('sponsorForm');
                    if (form) {
                        const errors = form.querySelectorAll('.validation-error');
                        errors.forEach(error => {
                            error.textContent = '';
                            error.style.color = '';
                        });

                        const inputs = form.querySelectorAll('input, textarea');
                        inputs.forEach(input => {
                            input.style.borderColor = '';
                        });
                    }

                    // Set up validation for inputs
                    setupSponsorFormValidation();
                };
            }
        }

        // Update existing sponsor form submission to include validation
        function updateSponsorFormSubmission() {
            const sponsorForm = document.getElementById('sponsorForm');
            if (!sponsorForm) return;

            // Remove existing submit event listener and add new one
            const newForm = sponsorForm.cloneNode(true);
            sponsorForm.parentNode.replaceChild(newForm, sponsorForm);

            newForm.addEventListener('submit', function (e) {
                e.preventDefault();

                // Validate form before submission
                if (!validateSponsorForm()) {
                    showNotification('Please fix the errors in the form', 'error');
                    return false;
                }

                // If validation passes, continue with existing submission logic
                const btn = document.getElementById('sponsorSubmitBtn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner"></span> Processing...';
                btn.disabled = true;
                btn.style.opacity = "0.6";

                const formData = new FormData(this);

                fetch('sponsor_mahal.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            closeSponsorModal();
                            this.reset();

                            // Clear all validation errors
                            const errors = this.querySelectorAll('.validation-error');
                            errors.forEach(error => {
                                error.textContent = '';
                                error.style.color = '';
                            });

                            const inputs = this.querySelectorAll('input, textarea');
                            inputs.forEach(input => {
                                input.style.borderColor = '';
                            });
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred. Please try again.', 'error');
                    })
                    .finally(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        btn.style.opacity = "1";
                    });
            });
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function () {
            setupSponsorModalValidation();
            updateSponsorFormSubmission();
        });

        // Update renewal form to handle payment confirmation
        function updateRenewalForm() {
            const select = document.getElementById('plan_select');
            if (!select) return;
            const cycleElement = document.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const cycle = cycleElement.value;
            const selectedOption = select.options[select.selectedIndex];

            let price = 0;
            if (selectedOption.value) {
                price = parseFloat(cycle === 'month' ? selectedOption.getAttribute('data-monthly') : selectedOption.getAttribute('data-yearly'));
            }

            const renewBtn = document.getElementById('renewBtn');
            const totalDisplay = document.getElementById('totalDisplay');
            const renewalAmountDisplay = document.getElementById('renewalAmountDisplay');
            const confirmationAmount = document.getElementById('confirmationAmount');

            if (price > 0) {
                totalDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                renewalAmountDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                confirmationAmount.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                // Update QR code with amount
                updateRenewalQRCode(price);

                // Enable/disable button based on payment confirmation
                const paymentConfirmed = document.getElementById('renewal_payment_confirmed');
                if (paymentConfirmed && paymentConfirmed.checked) {
                    renewBtn.disabled = false;
                } else {
                    renewBtn.disabled = true;
                }
            } else {
                renewBtn.disabled = true;
            }
        }

        // Update sponsored renewal form
        function updateSponsoredRenewalForm() {
            const select = document.getElementById('spon_plan_select');
            const form = document.getElementById('sponsoredRenewalForm');
            if (!select || !form) return;

            const durationType = form.querySelector('input[name="duration_type"]:checked').value;
            const selectedOption = select.options[select.selectedIndex];
            let price = 0;

            if (selectedOption.value) {
                price = parseFloat(durationType === 'month' ? selectedOption.getAttribute('data-monthly') : selectedOption.getAttribute('data-yearly'));
            }

            const btn = document.getElementById('sponRenewBtn');
            const totalDisplay = document.getElementById('sponTotalDisplay');
            const sponsoredAmountDisplay = document.getElementById('sponsoredAmountDisplay');
            const sponsoredConfirmationAmount = document.getElementById('sponsoredConfirmationAmount');

            if (price > 0) {
                totalDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                sponsoredAmountDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                sponsoredConfirmationAmount.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                // Update QR code with amount
                updateSponsoredQRCode(price);

                // Enable/disable button based on payment confirmation
                const paymentConfirmed = document.getElementById('sponsored_payment_confirmed');
                if (paymentConfirmed && paymentConfirmed.checked) {
                    btn.disabled = false;
                    btn.style.opacity = "1";
                } else {
                    btn.disabled = true;
                    btn.style.opacity = "0.6";
                }
            } else {
                btn.disabled = true;
                btn.style.opacity = "0.6";
            }
        }

        // Update renewal QR code
        function updateRenewalQRCode(amount) {
            const upiData = document.getElementById('renewal-upi-data');
            if (!upiData) return;

            const pa = upiData.getAttribute('data-pa');
            const pn = upiData.getAttribute('data-pn');

            // UPI URL Format
            let upiUrl = `upi://pay?pa=${encodeURIComponent(pa)}&pn=${encodeURIComponent(pn)}&cu=INR`;
            if (amount && parseFloat(amount) > 0) {
                upiUrl += `&am=${encodeURIComponent(amount)}`;
            }
            upiUrl += `&tn=Subscription Renewal`;

            const qrContainer = document.getElementById('renewalQrcode');
            if (qrContainer) {
                qrContainer.innerHTML = ''; // Clear previous QR
                new QRCode(qrContainer, {
                    text: upiUrl,
                    width: 120,
                    height: 120,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }

        // Update sponsored QR code
        function updateSponsoredQRCode(amount) {
            const upiData = document.getElementById('sponsored-upi-data');
            if (!upiData) return;

            const pa = upiData.getAttribute('data-pa');
            const pn = upiData.getAttribute('data-pn');

            // UPI URL Format
            let upiUrl = `upi://pay?pa=${encodeURIComponent(pa)}&pn=${encodeURIComponent(pn)}&cu=INR`;
            if (amount && parseFloat(amount) > 0) {
                upiUrl += `&am=${encodeURIComponent(amount)}`;
            }
            upiUrl += `&tn=Sponsored Renewal`;

            const qrContainer = document.getElementById('sponsoredQrcode');
            if (qrContainer) {
                qrContainer.innerHTML = ''; // Clear previous QR
                new QRCode(qrContainer, {
                    text: upiUrl,
                    width: 120,
                    height: 120,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }

        // Update the existing calculateTotal and calculateSponTotal functions
        function calculateTotal() {
            updateRenewalForm();
        }

        function calculateSponTotal() {
            updateSponsoredRenewalForm();
        }

        // Add event listeners for payment confirmation checkboxes
        document.addEventListener('DOMContentLoaded', function () {
            // For self renewal
            const renewalCheckbox = document.getElementById('renewal_payment_confirmed');
            if (renewalCheckbox) {
                renewalCheckbox.addEventListener('change', function () {
                    const renewBtn = document.getElementById('renewBtn');
                    if (this.checked && parseFloat(document.getElementById('totalDisplay').textContent.replace(/,/g, '')) > 0) {
                        renewBtn.disabled = false;
                    } else {
                        renewBtn.disabled = true;
                    }
                });
            }

            // For sponsored renewal
            const sponsoredCheckbox = document.getElementById('sponsored_payment_confirmed');
            if (sponsoredCheckbox) {
                sponsoredCheckbox.addEventListener('change', function () {
                    const sponBtn = document.getElementById('sponRenewBtn');
                    if (this.checked && parseFloat(document.getElementById('sponTotalDisplay').textContent.replace(/,/g, '')) > 0) {
                        sponBtn.disabled = false;
                        sponBtn.style.opacity = "1";
                    } else {
                        sponBtn.disabled = true;
                        sponBtn.style.opacity = "0.6";
                    }
                });
            }

            // Initialize forms
            updateRenewalForm();
            updatePlanPrices();
        });

        // Modify the submitRenewal function to check payment confirmation
        function submitRenewal() {
            const form = document.getElementById('renewalForm');
            const formData = new FormData(form);
            const btn = document.getElementById('renewBtn');
            const originalText = btn.innerHTML;

            // Check payment confirmation
            const paymentConfirmed = document.getElementById('renewal_payment_confirmed');
            if (paymentConfirmed && !paymentConfirmed.checked) {
                showNotification('Please confirm that you have completed the payment by checking the box.', 'error');
                return;
            }

            // Validate
            if (!formData.get('plan_id')) {
                showNotification('Please select a plan', 'error');
                return;
            }

            const amount = parseFloat(document.getElementById('totalDisplay').textContent.replace(/,/g, ''));
            if (amount <= 0) {
                showNotification('Please select a valid plan', 'error');
                return;
            }

            // Add payment details to form data
            formData.append('amount', amount);
            formData.append('payment_confirmed', paymentConfirmed ? '1' : '0');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Processing...';

            fetch('submit_subscription_request.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Renewal request submitted successfully! A confirmation email has been sent.', 'success');
                        form.reset();

                        // Reset payment confirmation
                        if (paymentConfirmed) paymentConfirmed.checked = false;

                        // Update forms
                        updatePlanPrices();
                        updateRenewalForm();

                        // Reset QR code
                        updateRenewalQRCode(0);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An unexpected error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                });
        }

        // Modify the submitSponsoredRenewal function
        function submitSponsoredRenewal() {
            const form = document.getElementById('sponsoredRenewalForm');
            const formData = new FormData(form);
            const btn = document.getElementById('sponRenewBtn');
            const originalText = btn.innerHTML;

            // Check payment confirmation
            const paymentConfirmed = document.getElementById('sponsored_payment_confirmed');
            if (paymentConfirmed && !paymentConfirmed.checked) {
                showNotification('Please confirm that you have completed the payment by checking the box.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Submitting...';
            btn.style.opacity = "0.6";

            const amount = parseFloat(document.getElementById('sponTotalDisplay').textContent.replace(/,/g, ''));
            if (amount <= 0) {
                showNotification('Please select a valid plan', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right"></i> Submit Request';
                btn.style.opacity = "1";
                return;
            }

            // Add payment details to form data
            formData.append('amount', amount);
            formData.append('payment_confirmed', paymentConfirmed ? '1' : '0');

            fetch('submit_subscription_request.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Success: Renewal request submitted! A confirmation email has been sent.', 'success');
                        closeSponsoredRenewalModal();

                        // Refresh the sponsored list after successful renewal
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An unexpected error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    updateSponsoredRenewalForm();
                    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Submit Request';
                    btn.style.opacity = "1";
                });
        }

        // Modal functions
        const renewModal = document.getElementById('renewModal');

        function openRenewModal() {
            renewModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            document.getElementById('renewalModalForm').reset();
            updateModalPlanPrices();
            calculateModalTotal();
        }

        function closeRenewModal() {
            renewModal.style.display = 'none';
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target == renewModal) closeRenewModal();
        }

        // Modal-specific functions
        function updateModalPlanPrices() {
            const form = document.getElementById('renewalModalForm');
            if (!form) return;
            const cycleElement = form.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const durationType = cycleElement.value;
            const select = document.getElementById('modal_plan_select');
            if (!select) return;
            const options = select.querySelectorAll('option');

            options.forEach(opt => {
                if (opt.value) {
                    const price = durationType === 'month' ? opt.dataset.monthly : opt.dataset.yearly;
                    const text = durationType === 'month' ? '/month' : '/year';
                    opt.textContent = opt.textContent.replace(/\(.*?\)/, `(₹${parseFloat(price).toLocaleString('en-IN', { minimumFractionDigits: 2 })}${text})`);
                }
            });
            calculateModalTotal();
        }

        function calculateModalTotal() {
            const select = document.getElementById('modal_plan_select');
            const form = document.getElementById('renewalModalForm');
            if (!select || !form) return;

            const cycleElement = form.querySelector('input[name="duration_type"]:checked');
            if (!cycleElement) return;
            const durationType = cycleElement.value;
            const selectedOption = select.options[select.selectedIndex];
            let price = 0;

            if (selectedOption.value) {
                price = parseFloat(durationType === 'month' ? selectedOption.getAttribute('data-monthly') : selectedOption.getAttribute('data-yearly'));
            }

            const btn = document.getElementById('modalRenewBtn');
            const totalDisplay = document.getElementById('modalTotalDisplay');
            const modalAmountDisplay = document.getElementById('modalAmountDisplay');
            const modalConfirmationAmount = document.getElementById('modalConfirmationAmount');

            if (price > 0) {
                totalDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                modalAmountDisplay.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                modalConfirmationAmount.textContent = price.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                // Update QR code with amount
                updateModalQRCode(price);

                // Enable/disable button based on payment confirmation
                const paymentConfirmed = document.getElementById('modal_payment_confirmed');
                if (paymentConfirmed && paymentConfirmed.checked) {
                    btn.disabled = false;
                    btn.style.opacity = "1";
                } else {
                    btn.disabled = true;
                    btn.style.opacity = "0.6";
                }
            } else {
                btn.disabled = true;
                btn.style.opacity = "0.6";
            }
        }

        function updateModalQRCode(amount) {
            const upiData = document.getElementById('modal-upi-data');
            if (!upiData) return;

            const pa = upiData.getAttribute('data-pa');
            const pn = upiData.getAttribute('data-pn');

            // UPI URL Format
            let upiUrl = `upi://pay?pa=${encodeURIComponent(pa)}&pn=${encodeURIComponent(pn)}&cu=INR`;
            if (amount && parseFloat(amount) > 0) {
                upiUrl += `&am=${encodeURIComponent(amount)}`;
            }
            upiUrl += `&tn=Subscription Renewal`;

            const qrContainer = document.getElementById('modalQrcode');
            if (qrContainer) {
                qrContainer.innerHTML = ''; // Clear previous QR
                new QRCode(qrContainer, {
                    text: upiUrl,
                    width: 120,
                    height: 120,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }

        function submitRenewalFromModal() {
            const form = document.getElementById('renewalModalForm');
            const formData = new FormData(form);
            const btn = document.getElementById('modalRenewBtn');
            const originalText = btn.innerHTML;

            // Check payment confirmation
            const paymentConfirmed = document.getElementById('modal_payment_confirmed');
            if (paymentConfirmed && !paymentConfirmed.checked) {
                showNotification('Please confirm that you have completed the payment by checking the box.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Submitting...';
            btn.style.opacity = "0.6";

            const amount = parseFloat(document.getElementById('modalTotalDisplay').textContent.replace(/,/g, ''));
            if (amount <= 0) {
                showNotification('Please select a valid plan', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right"></i> Submit Renewal Request';
                btn.style.opacity = "1";
                return;
            }

            // Add payment details to form data
            formData.append('amount', amount);
            formData.append('payment_confirmed', paymentConfirmed ? '1' : '0');

            fetch('submit_subscription_request.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Success: Renewal request submitted! A confirmation email has been sent.', 'success');
                        closeRenewModal();

                        // Refresh the page after successful renewal
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An unexpected error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Submit Renewal Request';
                    btn.style.opacity = "1";
                });
        }

        // Add event listener for payment confirmation checkbox
        document.addEventListener('DOMContentLoaded', function () {
            // For modal renewal
            const modalCheckbox = document.getElementById('modal_payment_confirmed');
            if (modalCheckbox) {
                modalCheckbox.addEventListener('change', function () {
                    const modalBtn = document.getElementById('modalRenewBtn');
                    if (this.checked && parseFloat(document.getElementById('modalTotalDisplay').textContent.replace(/,/g, '')) > 0) {
                        modalBtn.disabled = false;
                        modalBtn.style.opacity = "1";
                    } else {
                        modalBtn.disabled = true;
                        modalBtn.style.opacity = "0.6";
                    }
                });
            }// Add this comprehensive success notification function
            function showSubmitSuccess(message = 'Submitted successfully!', autoClose = true, reload = false) {
                // Create a more prominent success notification
                const successAlert = document.createElement('div');
                successAlert.className = 'alert alert-success';
                successAlert.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        z-index: 9999;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 8px 24px rgba(39, 174, 96, 0.3);
        max-width: 350px;
    `;

                successAlert.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; background: #27ae60; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-check" style="color: white; font-size: 18px;"></i>
            </div>
            <div style="flex: 1;">
                <strong style="display: block; margin-bottom: 4px;">Success!</strong>
                <span>${message}</span>
            </div>
        </div>
    `;

                document.body.appendChild(successAlert);

                if (autoClose) {
                    setTimeout(() => {
                        successAlert.style.animation = 'slideOutRight 0.3s ease';
                        setTimeout(() => successAlert.remove(), 300);
                    }, 3000);
                }

                if (reload) {
                    setTimeout(() => location.reload(), 2000);
                }

                return successAlert;
            }

            // Add CSS animations
            const style = document.createElement('style');
            style.textContent = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    @keyframes slideOutRight {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }
`;
            document.head.appendChild(style);

            // Update all form submissions to use the new success message
            document.addEventListener('DOMContentLoaded', function () {
                // 1. Profile Edit Form
                const editForm = document.getElementById('editProfileForm');
                if (editForm) {
                    editForm.addEventListener('submit', function (e) {
                        // This will show PHP success message, but we can also show JS notification
                        setTimeout(() => {
                            if (window.location.search.includes('success=1')) {
                                showSubmitSuccess('Profile updated successfully!', true, false);
                            }
                        }, 100);
                    });
                }

                // 2. Password Change Form
                const passwordForm = document.getElementById('changePasswordForm');
                if (passwordForm) {
                    const originalSubmit = passwordForm.onsubmit;
                    passwordForm.onsubmit = function (e) {
                        // Your existing validation logic here
                        // Then show success
                        const isValid = true; // Replace with your validation logic

                        if (isValid) {
                            showSubmitSuccess('Password changed successfully!', true, false);
                        }

                        if (originalSubmit) return originalSubmit.call(this, e);
                    };
                }

                // 3. Monthly Fee Form
                const feeForm = document.querySelector('.fee-update-form');
                if (feeForm) {
                    feeForm.addEventListener('submit', function (e) {
                        e.preventDefault();

                        const btn = this.querySelector('button[type="submit"]');
                        const originalText = btn.innerHTML;

                        btn.innerHTML = '<span class="spinner"></span> Updating...';
                        btn.disabled = true;

                        // Simulate API call
                        setTimeout(() => {
                            showSubmitSuccess('Monthly fee updated successfully!', true, true);
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                            this.submit(); // Actually submit the form
                        }, 1000);
                    });
                }

                // 4. All buttons with type="submit"
                document.querySelectorAll('button[type="submit"]').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const form = this.closest('form');
                        if (form && !form.classList.contains('no-success')) {
                            // Add loading state
                            const originalText = this.innerHTML;
                            this.innerHTML = '<span class="spinner"></span> Submitting...';
                            this.disabled = true;

                            // Reset after form submission
                            setTimeout(() => {
                                this.innerHTML = originalText;
                                this.disabled = false;

                                // Show success message (you can customize based on form ID)
                                const formId = form.id;
                                let message = 'Submitted successfully!';

                                switch (formId) {
                                    case 'editProfileForm':
                                        message = 'Profile updated successfully!';
                                        break;
                                    case 'changePasswordForm':
                                        message = 'Password changed successfully!';
                                        break;
                                    case 'sponsorForm':
                                        message = 'New mahal sponsored successfully!';
                                        break;
                                    case 'renewalModalForm':
                                        message = 'Renewal request submitted!';
                                        break;
                                    case 'sponsoredRenewalForm':
                                        message = 'Sponsored renewal submitted!';
                                        break;
                                    default:
                                        message = 'Submitted successfully!';
                                }

                                showSubmitSuccess(message, true, formId !== 'changePasswordForm');
                            }, 1500);
                        }
                    });
                });
            });
        });

        // ─── Additional Dues Form Toggle ───────────────────────────────
        function toggleDuesForm() {
            const wrapper = document.getElementById('addDueFormWrapper');
            const btn = document.getElementById('toggleDuesBtn');
            if (!wrapper) return;
            const isHidden = wrapper.style.display === 'none' || wrapper.style.display === '';
            if (isHidden) {
                wrapper.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-times"></i> Close';
                btn.classList.remove('blue');
                btn.classList.add('white');
                btn.style.border = '1px solid var(--border)';
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                wrapper.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-plus"></i> Add New Due';
                btn.classList.remove('white');
                btn.classList.add('blue');
                btn.style.border = '';
            }
        }
        // ─── PUBLIC PROFILE ADMIN LOGIC ────────────────────────────────────
        (function initPromo() {
            const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');

            function updatePromoPreview() {
                const slug = document.getElementById('promoSlug')?.value.trim();
                const link = document.getElementById('promoPreviewLink');
                const hint = document.getElementById('promoSlugHint');
                if (!link || !hint) return;
                if (slug) {
                    const previewUrl = baseUrl + '/mahal/' + slug;
                    link.href = previewUrl;
                    link.style.display = '';
                    hint.textContent = 'Public URL: ' + previewUrl;
                } else {
                    link.href = '#';
                    link.style.display = 'none';
                    hint.textContent = '';
                }
            }
            // Expose globally for oninput handler in HTML
            window.updatePromoPreview = updatePromoPreview;

            function renderGallery(items) {
                const grid = document.getElementById('galleryGrid');
                if (!grid) return;
                if (!items || items.length === 0) {
                    grid.innerHTML = '<p style="color:var(--text-light);font-size:13px">No photos yet. Upload some above.</p>';
                    return;
                }
                grid.innerHTML = items.map(img => `
                    <div style="position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:var(--card-alt);border:2px solid ${img.is_primary == 1 ? '#f59e0b' : 'var(--border)'};transition:border-color 0.2s;">
                        <img src="${baseUrl}/uploads/mahal_gallery/${img.image_path}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                        ${img.is_primary == 1
                        ? `<div style="position:absolute;top:4px;left:4px;background:#f59e0b;color:#000;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;display:flex;align-items:center;gap:4px;">★ HERO</div>`
                        : `<button onclick="setPrimaryImage(${img.id})"
                                style="position:absolute;top:4px;left:4px;background:rgba(0,0,0,0.65);border:1px solid rgba(245,158,11,0.6);color:#f59e0b;font-size:10px;font-weight:600;padding:2px 8px;border-radius:100px;cursor:pointer;transition:all 0.2s;"
                                onmouseover="this.style.background='#f59e0b';this.style.color='#000';"
                                onmouseout="this.style.background='rgba(0,0,0,0.65)';this.style.color='#f59e0b';"
                                title="Set as hero background">★ Set Hero</button>`}
                        <button onclick="deleteGalleryImage(${img.id}, this)"
                            style="position:absolute;top:4px;right:4px;background:rgba(220,38,38,0.85);border:none;color:white;width:24px;height:24px;border-radius:50%;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;"
                            title="Delete">✕</button>
                        ${img.caption ? `<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:white;font-size:10px;padding:4px 6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">${img.caption}</div>` : ''}
                    </div>`).join('');
            }

            window.setPrimaryImage = function (id) {
                const fd = new FormData(); fd.append('id', id);
                fetch('mahal_promo_api.php?action=set_primary', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            fetch('mahal_promo_api.php?action=get').then(r => r.json()).then(d => renderGallery(d.gallery));
                        } else { alert('Error: ' + data.message); }
                    });
            };

            function renderCommittee(items) {
                const list = document.getElementById('committeeList');
                if (!list) return;
                if (!items || items.length === 0) {
                    list.innerHTML = '<p style="color:var(--text-light);font-size:13px">No committee members added yet.</p>';
                    return;
                }
                list.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">` +
                    items.map(m => `
                    <div style="background:var(--card-alt);border:1px solid var(--border);border-radius:8px;overflow:hidden;text-align:center;">
                        ${m.image_path
                            ? `<img src="${baseUrl}/uploads/mahal_committee/${m.image_path}" style="width:100%;aspect-ratio:1;object-fit:cover;" loading="lazy">`
                            : `<div style="width:100%;aspect-ratio:1;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;color:var(--text-light);">${m.name.charAt(0).toUpperCase()}</div>`}
                        <div style="padding:12px 10px;">
                            <div style="font-weight:700;font-size:14px;margin-bottom:2px;">${m.name}</div>
                            ${m.role ? `<div style="font-size:11px;color:var(--accent);font-weight:600;margin-bottom:6px;">${m.role}</div>` : ''}
                            ${m.phone ? `<div style="font-size:12px;color:var(--text-light);margin-bottom:8px;">${m.phone}</div>` : ''}
                            <button onclick="deleteCommitteeMember(${m.id}, this)"
                                style="background:linear-gradient(135deg,#ef4444,#dc2626);color:white;border:none;padding:4px 12px;border-radius:6px;font-size:12px;cursor:pointer;">
                                <i class='fas fa-trash-alt'></i> Remove
                            </button>
                        </div>
                    </div>`).join('') + '</div>';
            }

            // Load all promo data on page load
            function loadPromoData() {
                fetch('mahal_promo_api.php?action=get')
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('promoLoadingMsg').style.display = 'none';
                        document.getElementById('promoContent').style.display = 'block';
                        if (data.success) {
                            if (data.profile) {
                                document.getElementById('promoSlug').value = data.profile.slug || '';
                                document.getElementById('promoDesc').value = data.profile.description || '';
                                document.getElementById('promoEstYear').value = data.profile.established_year || '';
                                document.getElementById('promoPublished').checked = (data.profile.is_published == 1);
                            }
                            updatePromoPreview();
                            renderGallery(data.gallery || []);
                            renderCommittee(data.committee || []);
                        }
                    })
                    .catch(err => {
                        console.error('Error loading promo data:', err);
                        document.getElementById('promoLoadingMsg').textContent = 'Failed to load profile data.';
                    });
            }

            // Save slug + description
            window.savePromoProfile = function () {
                const slug = document.getElementById('promoSlug').value.trim();
                const desc = document.getElementById('promoDesc').value;
                const year = document.getElementById('promoEstYear').value.trim();
                const pub = document.getElementById('promoPublished').checked ? '1' : '0';
                const btn = document.getElementById('savePromoBtn');
                const msg = document.getElementById('promoSaveMsg');

                if (!slug) { msg.textContent = '⚠ Slug is required'; msg.style.color = 'var(--error)'; return; }

                const fd = new FormData();
                fd.append('slug', slug);
                fd.append('description', desc);
                fd.append('established_year', year);
                if (pub === '1') fd.append('is_published', '1');

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                fetch('mahal_promo_api.php?action=save_profile', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            msg.textContent = '✓ Saved!'; msg.style.color = 'var(--success)';
                            updatePromoPreview();
                        } else {
                            msg.textContent = '✕ ' + data.message; msg.style.color = 'var(--error)';
                        }
                        setTimeout(() => { msg.textContent = ''; }, 4000);
                    })
                    .catch(err => { msg.textContent = '✕ Network error'; msg.style.color = 'var(--error)'; })
                    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Profile Settings'; });
            };

            // Upload gallery images (multi-file)
            window.uploadGalleryImages = function (input) {
                const files = Array.from(input.files);
                if (!files.length) return;
                const msg = document.getElementById('galleryUploadMsg');
                msg.textContent = `Uploading ${files.length} image(s)...`;

                let done = 0;
                files.forEach(file => {
                    const fd = new FormData();
                    fd.append('image', file);
                    fetch('mahal_promo_api.php?action=upload_gallery', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            done++;
                            if (done === files.length) {
                                msg.textContent = '✓ Uploaded!';
                                setTimeout(() => { msg.textContent = ''; }, 3000);
                                // Refresh gallery
                                fetch('mahal_promo_api.php?action=get').then(r => r.json()).then(d => renderGallery(d.gallery));
                            }
                        })
                        .catch(() => { msg.textContent = '✕ Upload failed'; });
                });
                input.value = '';
            };

            // Delete gallery image
            window.deleteGalleryImage = function (id, btnEl) {
                if (!confirm('Delete this photo from the gallery?')) return;
                btnEl.disabled = true;
                const fd = new FormData(); fd.append('id', id);
                fetch('mahal_promo_api.php?action=delete_gallery', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            fetch('mahal_promo_api.php?action=get').then(r => r.json()).then(d => renderGallery(d.gallery));
                        } else { alert('Error: ' + data.message); btnEl.disabled = false; }
                    });
            };

            // Add committee member
            window.addCommitteeMember = function () {
                const name = document.getElementById('cmName').value.trim();
                const role = document.getElementById('cmRole').value.trim();
                const phone = document.getElementById('cmPhone').value.trim();
                const photo = document.getElementById('cmPhoto').files[0];
                const btn = document.getElementById('addCmBtn');
                const msg = document.getElementById('cmMsg');

                if (!name) { msg.textContent = '⚠ Name is required'; msg.style.color = 'var(--error)'; return; }

                const fd = new FormData();
                fd.append('name', name); fd.append('role', role); fd.append('phone', phone);
                if (photo) fd.append('image', photo);

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

                fetch('mahal_promo_api.php?action=save_committee', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            msg.textContent = '✓ Member added!'; msg.style.color = 'var(--success)';
                            document.getElementById('cmName').value = '';
                            document.getElementById('cmRole').value = '';
                            document.getElementById('cmPhone').value = '';
                            document.getElementById('cmPhoto').value = '';
                            fetch('mahal_promo_api.php?action=get').then(r => r.json()).then(d => renderCommittee(d.committee));
                        } else {
                            msg.textContent = '✕ ' + data.message; msg.style.color = 'var(--error)';
                        }
                        setTimeout(() => { msg.textContent = ''; }, 4000);
                    })
                    .catch(() => { msg.textContent = '✕ Network error'; msg.style.color = 'var(--error)'; })
                    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Add Member'; });
            };

            // Delete committee member
            window.deleteCommitteeMember = function (id, btnEl) {
                if (!confirm('Remove this committee member?')) return;
                btnEl.disabled = true;
                const fd = new FormData(); fd.append('id', id);
                fetch('mahal_promo_api.php?action=delete_committee', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            fetch('mahal_promo_api.php?action=get').then(r => r.json()).then(d => renderCommittee(d.committee));
                        } else { alert('Error: ' + data.message); btnEl.disabled = false; }
                    });
            };

            // Init
            loadPromoData();
        })();
    </script>

</body>

</html>