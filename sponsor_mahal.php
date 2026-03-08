<?php
// sponsor_mahal.php - Backend for Sponsoring a Mahal
// Replicates index.php registration logic + records EXPENSE transaction for the sponsor

require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/db_connection.php';

// JSON helper
function send_json($arr)
{
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = get_db_connection();
    if (isset($db['error'])) {
        send_json(['success' => false, 'message' => $db['error']]);
    }
    $conn = $db['conn'];
    $sponsor_id = $_SESSION['user_id'];

    // 1. Validate Payment Confirmation
    $payment_confirmed = isset($_POST['payment_confirmed']) && $_POST['payment_confirmed'] == '1';
    if (!$payment_confirmed) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Please confirm that you have completed the payment.']);
    }

    // 2. Validate Inputs (Replicating index.php)
    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $address = $conn->real_escape_string($_POST['address'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $phone = $conn->real_escape_string($_POST['phone'] ?? ''); // user input
    $registration_no = $conn->real_escape_string($_POST['registration_no'] ?? '');
    $password_input = $_POST['password'] ?? '';

    // Fetch Registration Charge from Settings for validation/default
    $reg_fee_query = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'registration_charge'");
    $registration_fee = ($reg_fee_query && $row = $reg_fee_query->fetch_assoc()) ? floatval($row['setting_value']) : 0.0;

    // Payment Amount from form
    $amount = floatval($_POST['amount'] ?? 0);

    // If amount is 0 (or not passed), use the registration fee. 
    // If it is passed, we could validation it, but for now we'll just allow it if it's > 0, 
    // or maybe enforce it matches registration_fee if that is set > 0.
    // Let's enforce that if registration_fee is > 0, the amount must match or be greater?
    // User request was "fetched from settings", so typically implies that IS the price.
    // Best practice: Use the settings value as the source of truth if available.
    if ($registration_fee > 0 && $amount <= 0) {
        $amount = $registration_fee;
    }

    $payment_mode = strtoupper(trim($_POST['payment_mode'] ?? 'CASH')); // Default to CASH if not specified

    $created_at = date('Y-m-d H:i:s');
    $role = "user";
    $status = "pending";
    $plan = null; // Or handled otherwise if plans are needed

    if (trim($name) === '') {
        $conn->close();
        send_json(['success' => false, 'message' => 'Please enter mahal name.']);
    }
    if (strlen(trim($address)) < 10) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Address should be at least 10 characters.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Invalid email address.']);
    }

    $phone_digits = preg_replace('/\D+/', '', $phone);
    if (strlen($phone_digits) != 10) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Phone number must be exactly 10 digits.']);
    }

    if (trim($registration_no) === '') {
        $conn->close();
        send_json(['success' => false, 'message' => 'Please enter registration number.']);
    }

    $pwd_regex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%?&])[A-Za-z\d@$!%?&]{8,}$/';
    if (!preg_match($pwd_regex, $password_input)) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, number and special character.']);
    }

    if ($amount <= 0) {
        $conn->close();
        send_json(['success' => false, 'message' => 'Invalid payment amount.']);
    }

    // 3. Check for duplicates
    $check = $conn->prepare("SELECT id FROM register WHERE email = ? OR registration_no = ?");
    $check->bind_param("ss", $email, $registration_no);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        $conn->close();
        send_json(['success' => false, 'message' => 'Email or Registration Number already exists.']);
    }
    $check->close();

    // 4. Start Transaction
    $conn->begin_transaction();

    try {
        // A. Insert into register
        $password_hashed = password_hash($password_input, PASSWORD_BCRYPT);

        // Ensure sponsored_by column exists (Safety check, though we ran schema update)
        // If it doesn't exist, this insert will fail if we include it. 
        // We assume schema update ran.

        $sqlReg = "INSERT INTO register (name, address, email, phone, registration_no, password, role, status, plan, created_at, sponsored_by) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtReg = $conn->prepare($sqlReg);
        if (!$stmtReg) {
            throw new Exception("Prepare register failed: " . $conn->error);
        }

        $stmtReg->bind_param("ssssssssssi", $name, $address, $email, $phone_digits, $registration_no, $password_hashed, $role, $status, $plan, $created_at, $sponsor_id);

        if (!$stmtReg->execute()) {
            throw new Exception("Registration failed: " . $stmtReg->error);
        }
        $new_mahal_id = $conn->insert_id;
        $stmtReg->close();

        // B. Insert into transactions (EXPENSE for the sponsor)
        // Type: EXPENSE
        // Category: SPONSORSHIP
        // Description: Sponsoring Mahal: [Name] (Reg: [RegNo])

        // Get receipt number logic (from finance-api.php methodology)
        $transYear = (int) date('Y');
        $receiptCounter = 0;
        $rcStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
        $rcStmt->bind_param("ii", $sponsor_id, $transYear);
        $rcStmt->execute();
        $rcRes = $rcStmt->get_result();
        if ($rcRow = $rcRes->fetch_assoc()) {
            $receiptCounter = (int) $rcRow['cnt'];
        }
        $rcStmt->close();

        $nextReceiptNum = $receiptCounter + 1;
        $receiptNumber = 'V' . $nextReceiptNum . '/' . $transYear; // 'V' for Voucher (Expense)

        $type = 'EXPENSE';
        $category = 'SPONSORSHIP';
        $description = "Sponsoring Mahal: $name (Reg: $registration_no)";
        $transaction_date = date('Y-m-d');

        $sqlTrans = "INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, payment_mode, receipt_no, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtTrans = $conn->prepare($sqlTrans);
        if (!$stmtTrans) {
            throw new Exception("Prepare transaction failed: " . $conn->error);
        }

        $stmtTrans->bind_param("isssdssss", $sponsor_id, $transaction_date, $type, $category, $amount, $description, $payment_mode, $receiptNumber, $created_at);

        if (!$stmtTrans->execute()) {
            throw new Exception("Transaction recording failed: " . $stmtTrans->error);
        }
        $stmtTrans->close();

        // C. Assign Default Plan & Create Subscription
        // 1. Fetch Default Plan Settings
        $planSettingsVars = [];
        $psSql = "SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ('default_plan', 'default_plan_duration_type', 'default_plan_duration_custom')";
        $psRes = $conn->query($psSql);
        if ($psRes) {
            while ($row = $psRes->fetch_assoc()) {
                $planSettingsVars[$row['setting_key']] = $row['setting_value'];
            }
        }

        $defaultPlanId = isset($planSettingsVars['default_plan']) ? intval($planSettingsVars['default_plan']) : 0;

        if ($defaultPlanId > 0) {
            // Get Plan Details
            $planTitle = '';
            $planQ = $conn->prepare("SELECT title FROM plans WHERE id = ?");
            if ($planQ) {
                $planQ->bind_param("i", $defaultPlanId);
                $planQ->execute();
                $planQ->bind_result($planTitle);
                $planQ->fetch();
                $planQ->close();
            }

            if ($planTitle) {
                // Calculation Duration
                $durType = isset($planSettingsVars['default_plan_duration_type']) ? intval($planSettingsVars['default_plan_duration_type']) : 1; // 1=Month
                $durCustom = isset($planSettingsVars['default_plan_duration_custom']) ? intval($planSettingsVars['default_plan_duration_custom']) : 30;

                $startDate = date('Y-m-d');
                $endDate = $startDate;

                if ($durType == 1) { // Month
                    $endDate = date('Y-m-d', strtotime('+1 month', strtotime($startDate)));
                } elseif ($durType == 2) { // Year
                    $endDate = date('Y-m-d', strtotime('+1 year', strtotime($startDate)));
                } elseif ($durType == 3) { // Custom Days
                    $endDate = date('Y-m-d', strtotime("+$durCustom days", strtotime($startDate)));
                }

                // Create subscriptions table if not exists (Lazy creation)
                $conn->query("CREATE TABLE IF NOT EXISTS subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    mahal_id INT NOT NULL,
                    plan_id INT NOT NULL,
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (mahal_id) REFERENCES register(id) ON DELETE CASCADE,
                    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Insert Subscription
                $subSql = "INSERT INTO subscriptions (mahal_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')";
                $subStmt = $conn->prepare($subSql);
                if ($subStmt) {
                    $subStmt->bind_param("iiss", $new_mahal_id, $defaultPlanId, $startDate, $endDate);
                    $subStmt->execute();
                    $subStmt->close();
                }

                // Update Register table with Plan Name
                $updReg = $conn->prepare("UPDATE register SET plan = ? WHERE id = ?");
                if ($updReg) {
                    $updReg->bind_param("si", $planTitle, $new_mahal_id);
                    $updReg->execute();
                    $updReg->close();
                }
            }
        }

        $conn->commit();
        $conn->close();
        send_json(['success' => true, 'message' => 'Mahal sponsored and registered successfully!']);

    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        send_json(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    send_json(['success' => false, 'message' => 'Invalid request method.']);
}
?>