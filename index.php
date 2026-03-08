<?php
/* --- secure session (must be first) --- */
require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers (keep these BEFORE any output) --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- if already logged in, never show index --- */
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
if (!empty($_SESSION['member_login']) && $_SESSION['member_login'] === true) {
    header("Location: member_dashboard.php");
    exit();
}

/* ------------------ JSON helper ------------------ */
function send_json($arr)
{
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit();
}

/* ------------------ DB ------------------ */
function get_db_connection()
{
    $servername = "localhost";
    $username = "u654847199_Sarfas2004";
    $password = "Sarfas@2004";
    $dbname = "u654847199_mahal";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        return ['error' => "Connection failed: " . $conn->connect_error];
    }
    $conn->set_charset('utf8mb4');
    return ['conn' => $conn];
}

/* ------------------ Site Logo URL ------------------ */
$site_logo_url = "logo.jpeg"; // If logo is in the same directory as your PHP file // REPLACE THIS WITH YOUR LOGO URL

/* ------------------ Get Payment Details ------------------ */
function get_payment_details()
{
    $db = get_db_connection();
    if (isset($db['error'])) {
        return ['error' => $db['error']];
    }

    $conn = $db['conn'];

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

/* ------------------ Helpers for MEMBER login ------------------ */
function norm_phone_10($s)
{
    $d = preg_replace('/\D+/', '', (string) $s);
    return $d ? substr($d, -10) : '';
}
function name_key4($name)
{
    $k = preg_replace('/[^A-Za-z]/', '', (string) $name);
    $k = strtolower($k);
    return substr($k, 0, 4);
}
function yyyy_from_dob($dob)
{
    if (!$dob)
        return '';
    $t = strtotime($dob);
    if ($t === false)
        return '';
    return date('Y', $t);
}
function derive_member_password($name, $dob)
{
    return name_key4($name) . yyyy_from_dob($dob);
}

/* ------------------ MEMBER LOGIN (AJAX or normal) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['member_login']) || isset($_POST['member_login_ajax']))) {
    $is_ajax = isset($_POST['member_login_ajax']) && $_POST['member_login_ajax'] == '1';

    $phone_in = norm_phone_10($_POST['member_phone'] ?? '');
    $pass_in = strtolower(trim($_POST['member_password'] ?? ''));

    if (!$phone_in || !$pass_in) {
        $msg = 'Please enter phone and password.';
        if ($is_ajax)
            send_json(['success' => false, 'message' => $msg]);
        echo "<script>alert('" . htmlspecialchars($msg, ENT_QUOTES) . "'); window.history.back();</script>";
        exit();
    }

    $db = get_db_connection();
    if (isset($db['error'])) {
        if ($is_ajax)
            send_json(['success' => false, 'message' => $db['error']]);
        else
            die($db['error']);
    }
    /** @var mysqli $conn */
    $conn = $db['conn'];

    $hit = null;

    // 1) HEAD members (normal members table)
    $sql = "SELECT m.id, m.head_name AS name, m.phone, m.dob, m.mahal_id
            FROM members m
            WHERE m.phone LIKE CONCAT('%', ?, '%') LIMIT 50";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $phone_in);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (norm_phone_10($row['phone']) === $phone_in) {
                $derived = derive_member_password($row['name'], $row['dob']);
                if ($derived && hash_equals($derived, $pass_in)) {
                    $hit = [
                        'member_type' => 'head',
                        'member_id' => (int) $row['id'],
                        'mahal_id' => (int) $row['mahal_id'],
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                    ];
                    break;
                }
            }
        }
        $stmt->close();
    }

    // 2) FAMILY members (if not found in head) - from normal members table
    if (!$hit) {
        $sqlF = "SELECT f.id, f.name, f.phone, f.dob, f.member_id AS parent_member_id
                 FROM family_members f
                 WHERE f.phone LIKE CONCAT('%', ?, '%') LIMIT 50";
        if ($stmtF = $conn->prepare($sqlF)) {
            $stmtF->bind_param("s", $phone_in);
            $stmtF->execute();
            $resF = $stmtF->get_result();
            while ($row = $resF->fetch_assoc()) {
                if (norm_phone_10($row['phone']) === $phone_in) {
                    $derived = derive_member_password($row['name'], $row['dob']);
                    if ($derived && hash_equals($derived, $pass_in)) {
                        $mahal_id = 0;
                        if ($pm = $conn->prepare("SELECT mahal_id FROM members WHERE id = ?")) {
                            $pm->bind_param("i", $row['parent_member_id']);
                            $pm->execute();
                            $maRes = $pm->get_result()->fetch_assoc();
                            if ($maRes)
                                $mahal_id = (int) $maRes['mahal_id'];
                            $pm->close();
                        }
                        $hit = [
                            'member_type' => 'family',
                            'member_id' => (int) $row['id'],
                            'mahal_id' => $mahal_id,
                            'name' => $row['name'],
                            'phone' => $row['phone'],
                            'parent_member_id' => (int) $row['parent_member_id'],
                        ];
                        break;
                    }
                }
            }
            $stmtF->close();
        }
    }

    // 3) SAHAKARI HEAD members ONLY (NO family members from sahakari_family_members)
    if (!$hit) {
        // Check if sahakari_members table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'sahakari_members'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $sqlS = "SELECT s.id, s.head_name AS name, s.phone, s.dob, s.mahal_id
                     FROM sahakari_members s
                     WHERE s.phone LIKE CONCAT('%', ?, '%') LIMIT 50";
            if ($stmtS = $conn->prepare($sqlS)) {
                $stmtS->bind_param("s", $phone_in);
                $stmtS->execute();
                $resS = $stmtS->get_result();
                while ($row = $resS->fetch_assoc()) {
                    if (norm_phone_10($row['phone']) === $phone_in) {
                        $derived = derive_member_password($row['name'], $row['dob']);
                        if ($derived && hash_equals($derived, $pass_in)) {
                            $hit = [
                                'member_type' => 'sahakari_head',
                                'member_id' => (int) $row['id'],
                                'mahal_id' => (int) $row['mahal_id'],
                                'name' => $row['name'],
                                'phone' => $row['phone'],
                            ];
                            break;
                        }
                    }
                }
                $stmtS->close();
            }
        }
    }

    $conn->close();

    if ($hit) {
        session_regenerate_id(true);
        $_SESSION['member_login'] = true;
        $_SESSION['member'] = $hit;
        $redirect = 'member_dashboard.php';
        if ($is_ajax) {
            send_json(['success' => true, 'message' => 'Login successful', 'redirect' => $redirect]);
        } else {
            echo "<script>window.location.href = '" . $redirect . "';</script>";
            exit();
        }
    } else {
        $msg = 'Invalid phone or password.';
        if ($is_ajax)
            send_json(['success' => false, 'message' => $msg]);
        echo "<script>alert('" . htmlspecialchars($msg, ENT_QUOTES) . "'); window.history.back();</script>";
        exit();
    }
}

/* ------------------ MAHAL/ADMIN LOGIN ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['login']) || isset($_POST['login_ajax']))) {
    $is_ajax = isset($_POST['login_ajax']) && $_POST['login_ajax'] == '1';

    $db = get_db_connection();
    if (isset($db['error'])) {
        if ($is_ajax)
            send_json(['success' => false, 'message' => $db['error']]);
        else
            die($db['error']);
    }
    $conn = $db['conn'];

    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $password_input = $_POST['password'] ?? '';

    $sql = "SELECT id, name, email, password, role FROM register WHERE email = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $err = $conn->error;
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'DB error: ' . $err]);
        else
            die("DB error: " . $err);
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password_input, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            $redirect = ($user['role'] == 'user') ? 'dashboard.php' : 'admin_dashboard.php';

            $stmt->close();
            $conn->close();

            if ($is_ajax) {
                send_json(['success' => true, 'message' => 'Login successful', 'redirect' => $redirect]);
            } else {
                echo "<script>window.location.href = '" . $redirect . "';</script>";
                exit();
            }
        } else {
            $stmt->close();
            $conn->close();
            if ($is_ajax)
                send_json(['success' => false, 'message' => 'Invalid password.']);
            else {
                echo "<script>alert('Error: Invalid password. Please try again.'); window.history.back();</script>";
                exit();
            }
        }
    } else {
        if ($stmt)
            $stmt->close();
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'No account found with this email.']);
        else {
            echo "<script>alert('Error: No account found with this email. Please register first.'); window.history.back();</script>";
            exit();
        }
    }
}

/* ------------------ REGISTRATION (updated with payment check) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['register']) || isset($_POST['register_ajax']))) {
    $is_ajax = isset($_POST['register_ajax']) && $_POST['register_ajax'] == '1';

    // Check payment confirmation if payment details exist
    if ($hasPaymentDetails) {
        $payment_confirmed = isset($_POST['payment_confirmed']) && $_POST['payment_confirmed'] == '1';
        if (!$payment_confirmed) {
            if ($is_ajax)
                send_json(['success' => false, 'message' => 'Please confirm that you have completed the payment.']);
            else {
                echo "<script>alert('Error: Please confirm that you have completed the payment by checking the box.'); window.history.back();</script>";
                exit();
            }
        }
    }

    $db = get_db_connection();
    if (isset($db['error'])) {
        if ($is_ajax)
            send_json(['success' => false, 'message' => $db['error']]);
        else
            die($db['error']);
    }
    $conn = $db['conn'];

    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $address = $conn->real_escape_string($_POST['address'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $phone = $conn->real_escape_string($_POST['phone'] ?? '');
    $registration_no = $conn->real_escape_string($_POST['registration_no'] ?? '');
    $password_input = $_POST['password'] ?? '';
    $created_at = date('Y-m-d H:i:s');

    $role = "user";
    $status = "pending";
    $plan = null;

    if (trim($name) === '') {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Please enter mahal name.']);
        else {
            echo "<script>alert('Please enter mahal name.'); window.history.back();</script>";
            exit();
        }
    }
    if (strlen(trim($address)) < 10) {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Address should be at least 10 characters.']);
        else {
            echo "<script>alert('Address should be at least 10 characters.'); window.history.back();</script>";
            exit();
        }
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Invalid email address.']);
        else {
            echo "<script>alert('Invalid email address.'); window.history.back();</script>";
            exit();
        }
    }

    $phone_digits = preg_replace('/\D+/', '', $phone);
    if (strlen($phone_digits) != 10) {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Phone number must be exactly 10 digits.']);
        else {
            echo "<script>alert('Phone number must be exactly 10 digits.'); window.history.back();</script>";
            exit();
        }
    }

    if (trim($registration_no) === '') {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Please enter registration number.']);
        else {
            echo "<script>alert('Please enter registration number.'); window.history.back();</script>";
            exit();
        }
    }

    $pwd_regex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%?&])[A-Za-z\d@$!%?&]{8,}$/';
    if (!preg_match($pwd_regex, $password_input)) {
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Password must be at least 8 characters with uppercase, lowercase, number and special character.']);
        else {
            echo "<script>alert('Password must be at least 8 characters with uppercase, lowercase, number and special character.'); window.history.back();</script>";
            exit();
        }
    }

    $email_check = $conn->prepare("SELECT id FROM register WHERE email = ?");
    if (!$email_check) {
        $err = $conn->error;
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Database error: ' . $err]);
        else
            die("Database error: " . $err);
    }
    $email_check->bind_param("s", $email);
    $email_check->execute();
    $email_check->store_result();
    if ($email_check->num_rows > 0) {
        $email_check->close();
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'This email is already registered.']);
        else {
            echo "<script>alert('Error: This email is already registered. Please use a different email.'); window.history.back();</script>";
            exit();
        }
    }
    $email_check->close();

    $password_hashed = password_hash($password_input, PASSWORD_BCRYPT);
    $sql = "INSERT INTO register (name, address, email, phone, registration_no, password, role, status, plan, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $err = $conn->error;
        $conn->close();
        if ($is_ajax)
            send_json(['success' => false, 'message' => 'Prepare failed: ' . $err]);
        else
            die("Prepare failed: " . $err);
    }

    $stmt->bind_param("ssssssssss", $name, $address, $email, $phone_digits, $registration_no, $password_hashed, $role, $status, $plan, $created_at);

    if ($stmt->execute()) {
        $new_mahal_id = $stmt->insert_id;
        $stmt->close();

        // --- Subscription Logic ---
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

                // Create subscriptions table if not exists
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
        // --- End Subscription Logic ---

        $conn->close();
        if ($is_ajax) {
            send_json(['success' => true, 'message' => 'Registration successful! Please login to continue.']);
        } else {
            echo "<script>alert('Registration successful! Please login to continue.'); window.location.href='index.php';</script>";
            exit();
        }
    } else {
        $error_msg = $stmt->error;
        $errno = $conn->errno;
        $stmt->close();
        $conn->close();
        if ($is_ajax) {
            if ($errno == 1062)
                send_json(['success' => false, 'message' => 'Email already exists.']);
            else
                send_json(['success' => false, 'message' => 'Database error: ' . $error_msg]);
        } else {
            if ($errno == 1062)
                echo "<script>alert('Error: Email already exists. Please use a different email.'); window.history.back();</script>";
            else
                echo "<script>alert('Error: " . addslashes($error_msg) . "'); window.history.back();</script>";
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mahal Management | Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Scheherazade+New:wght@400;700&display=swap"
        rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        /* CSS Variables */
        :root {
            --primary: #4a6fa5;
            --primary-dark: #3a5984;
            --primary-light: #6b8cc0;
            --secondary: #6bbaa7;
            --accent: #f18f8f;
            --text: #2c3e50;
            --text-light: #7f8c8d;
            --text-lighter: #bdc3c7;
            --bg: #f9fafb;
            --card: #ffffff;
            --card-alt: #f1f5f9;
            --border: #e2e8f0;
            --success: #27ae60;
            --error: #e74c3c;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            --radius: 10px;
            --transition: all 0.3s ease;
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: var(--text);
            line-height: 1.6;
        }

        /* Container */
        .container {
            width: 100%;
            max-width: 1000px;
            height: 600px;
            display: flex;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            background: var(--card);
        }

        /* Left Panel with Background Image */
        .welcome-panel {
            flex: 1;
            background-image: url('ok.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: white;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            position: relative;
            overflow: hidden;
        }

        /* Logo */
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 1;
            position: relative;
            margin-bottom: 30px;
        }

        .logo-image {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        /* Welcome Content */
        .welcome-content {
            z-index: 1;
            position: relative;
            text-align: center;
        }

        .welcome-content p {
            font-size: 16px;
            margin-bottom: 10px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }

        /* Bottom Text */
        .bottom-text {
            text-align: center;
            z-index: 1;
            position: absolute;
            bottom: 50px;
            left: 0;
            right: 0;
        }

        /* Custom Styling for Arabic Text */
        .calli-deco {
            font-size: 30px;
            font-family: "Scheherazade New", serif;
            transform: scaleY(1.9);
            letter-spacing: 3px;
            color: #000000;
            text-shadow: 1px 1px 2px rgba(255, 255, 255, 0.8);
            text-align: center;
            margin-left: -15px;
            width: 100%;
        }

        .calli-majlis-fancy {
            font-size: 33px;
            font-family: "Scheherazade New", serif;
            transform: scaleY(1.5);
            letter-spacing: 3px;
            color: #000000;
            text-shadow: 1px 1px 2px rgba(255, 255, 255, 0.8);
            margin-top: -20px;
            text-align: center;
            width: 100%;
        }

        /* Bottom Text */
        .tagline {
            font-family: 'Bodoni MT Condensed';
            color: #0B5394;
            font-size: 18px;
            letter-spacing: 0.6px;
            text-shadow: 1px 1px 2px rgba(255, 255, 255, 0.8);
            font-weight: 600;
            margin-right: -35px;
        }

        .bottom-text {
            text-align: center;
            z-index: 1;
            position: absolute;
            bottom: 20px;
            /* Reduced from 50px to move it down */
            left: 0;
            right: 0;
        }

        /* Right Panel */
        .forms-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            background: var(--card);
            overflow-y: auto;
            scrollbar-width: none;
        }

        .forms-panel::-webkit-scrollbar {
            display: none;
        }

        /* Forms Header */
        .forms-header {
            margin-bottom: 30px;
            text-align: center;
        }

        .forms-header h2 {
            font-size: 24px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .forms-header p {
            color: var(--text-light);
            font-size: 15px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            background: var(--card-alt);
            border-radius: 8px;
            padding: 4px;
            margin-bottom: 25px;
        }

        .tab {
            flex: 1;
            text-align: center;
            padding: 12px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-light);
            transition: var(--transition);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* Form Containers */
        .form-container {
            display: none;
        }

        .form-container.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Compact Payment Container */
        .payment-container {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .payment-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .payment-header h3 {
            font-size: 16px;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .payment-header h3 i {
            color: var(--primary);
        }

        .payment-amount {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            background: rgba(74, 111, 165, 0.1);
            padding: 5px 10px;
            border-radius: 6px;
        }

        .payment-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .payment-detail-item {
            display: flex;
            flex-direction: column;
        }

        .payment-detail-label {
            font-size: 11px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .payment-detail-value {
            font-size: 13px;
            color: var(--text);
            font-weight: 600;
            word-break: break-word;
        }

        .payment-qr {
            text-align: center;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .payment-qr img {
            width: 100px;
            height: 100px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 6px;
            background: white;
            margin-bottom: 6px;
        }

        .payment-qr p {
            font-size: 10px;
            color: var(--text-light);
            margin-top: 3px;
        }

        /* Payment Confirmation Checkbox */
        .payment-confirmation {
            background: linear-gradient(135deg, #e8f4ff 0%, #d4e7ff 100%);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 2px solid var(--primary-light);
        }

        .confirmation-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .confirmation-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 3px;
            cursor: pointer;
            accent-color: var(--primary);
            flex-shrink: 0;
        }

        .confirmation-label {
            flex: 1;
        }

        .confirmation-label label {
            font-size: 14px;
            color: var(--text);
            line-height: 1.5;
            cursor: pointer;
            display: block;
            font-weight: 500;
        }

        .confirmation-label strong {
            color: var(--primary);
            font-weight: 700;
        }

        .amount-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 5px;
            font-weight: 600;
            font-size: 12px;
            margin: 0 3px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text);
            font-size: 14px;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-lighter);
            font-size: 16px;
        }

        .input-with-icon input {
            padding-left: 42px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
            background: var(--card);
            color: var(--text);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .form-control.valid {
            border-color: var(--success);
        }

        .form-control.invalid {
            border-color: var(--error);
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-lighter);
            cursor: pointer;
            font-size: 16px;
        }

        /* Error Messages */
        .error {
            display: none;
            color: var(--error);
            font-size: 12px;
            margin-top: 6px;
            font-weight: 500;
        }

        .error.show {
            display: block;
        }

        /* Password Strength */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            background: var(--border);
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background 0.3s ease;
        }

        .strength-weak {
            background: var(--error);
            width: 33%;
        }

        .strength-medium {
            background: var(--accent);
            width: 66%;
        }

        .strength-strong {
            background: var(--success);
            width: 100%;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:hover {
            background: var(--primary-dark);
        }

        .btn:disabled {
            background: var(--text-lighter);
            cursor: not-allowed;
        }

        .btn:disabled:hover {
            background: var(--text-lighter);
        }

        /* Divider */
        .divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            background: var(--card);
            padding: 0 12px;
            position: relative;
            color: var(--text-light);
            font-size: 14px;
        }

        /* Form Links */
        .switch-form {
            text-align: center;
            margin-top: 20px;
            color: var(--text-light);
            font-size: 14px;
        }

        .switch-form a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        /* Password Hint */
        .password-hint {
            background: var(--card-alt);
            border-radius: 6px;
            padding: 12px;
            margin-top: 15px;
            font-size: 13px;
            color: var(--text-light);
            border-left: 3px solid var(--primary);
        }

        /* Toast */
        #toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            min-width: 280px;
            max-width: calc(100% - 40px);
            padding: 14px 18px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 9999;
            color: white;
            font-weight: 500;
        }

        #toast.success {
            background: var(--success);
        }

        #toast.error {
            background: var(--error);
        }

        #toast .toast-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        #toast .toast-message {
            flex: 1;
        }

        #toast .close-toast {
            margin-left: 12px;
            cursor: pointer;
            opacity: 0.9;
            font-weight: 700;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                max-width: 500px;
                height: auto;
            }

            .welcome-panel {
                padding: 40px 30px;
                min-height: 300px;
            }

            .forms-panel {
                padding: 40px 30px;
                overflow-y: visible;
            }

            .calli-deco {
                font-size: 24px;
            }

            .calli-majlis-fancy {
                font-size: 28px;
            }

            .tagline {
                font-size: 20px;
            }

            .bottom-text {
                position: relative;
                bottom: auto;
                margin-top: 30px;
            }

            .logo-image {
                width: 50px;
                height: 50px;
            }

            .payment-details {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .welcome-panel,
            .forms-panel {
                padding: 30px 20px;
            }

            .calli-deco {
                font-size: 20px;
            }

            .calli-majlis-fancy {
                font-size: 24px;
            }

            .tagline {
                font-size: 18px;
            }

            .logo-image {
                width: 45px;
                height: 45px;
            }

            .payment-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .payment-amount {
                align-self: stretch;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Left Panel with Background Image -->
        <div class="welcome-panel">
            <div class="logo">
                <?php if (!empty($site_logo_url)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo_url); ?>" alt="Site Logo" class="logo-image">
                <?php else: ?>
                    <div class="logo-icon">
                        <i class="fas fa-mosque"></i>
                    </div>
                <?php endif; ?>
                <div class="calli-deco">بِسْمِ اللّٰهِ الرَّحْمٰنِ الرَّحِيْمِ</div>
            </div>

            <div class="welcome-content">
                <h1 class="calli-majlis-fancy">مجلس</h1>
            </div>

            <div class="bottom-text">
                <p class="tagline">Digitalizing Community Management</p>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="forms-panel">
            <div class="forms-header">
                <h2>Welcome Back</h2>
                <p>Sign in to your account or create a new one</p>
            </div>

            <div class="tabs">
                <div class="tab active" data-tab="mahal-login">Mahal / Admin</div>
                <div class="tab" data-tab="member-login">Member</div>
            </div>

            <!-- Mahal/Admin Login Form -->
            <div class="form-container active" id="mahal-login">
                <form id="loginForm" method="POST" action="">
                    <div class="form-group">
                        <label for="login_email">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="login_email" name="email" class="form-control"
                                placeholder="your@email.com" required>
                        </div>
                        <span class="error" id="loginEmailError">Please enter a valid email address</span>
                    </div>

                    <div class="form-group">
                        <label for="login_password">Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="login_password" name="password" class="form-control"
                                placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="toggleLoginPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error" id="loginPasswordError">Please enter your password</span>
                    </div>

                    <button type="submit" name="login" class="btn">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </button>
                </form>

                <div class="divider"><span>OR</span></div>

                <div class="switch-form">
    Don't have an account? 
    <span style="color: #999; cursor: not-allowed;">Register Now (Disabled)</span>
</div>
            </div>

            <!-- Member Login Form -->
            <div class="form-container" id="member-login">
                <form id="memberLoginForm" method="POST" action="">
                    <div class="form-group">
                        <label for="member_phone">Mobile Number</label>
                        <div class="input-with-icon">
                            <i class="fas fa-phone"></i>
                            <input type="tel" id="member_phone" name="member_phone" class="form-control" inputmode="tel"
                                maxlength="16" placeholder="e.g. 9876543210" required>
                        </div>
                        <span class="error" id="memberPhoneError">Phone number must be exactly 10 digits and start with
                            6,7,8,9</span>
                    </div>

                    <div class="form-group">
                        <label for="member_password">Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="member_password" name="member_password" class="form-control"
                                placeholder="first 4 letters + birth year" required>
                            <button type="button" class="password-toggle" id="toggleMemberPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error" id="memberPasswordError">Please enter your password</span>
                    </div>

                    <button type="submit" name="member_login" class="btn">
                        <i class="fas fa-sign-in-alt"></i>
                        Member Login
                    </button>
                </form>

                <div class="password-hint">
                    <strong>Password Format:</strong> First 4 letters of your name + birth year (YYYY)<br>
                    Example: Name "Abdul Rahman", DOB "1990-05-10" → <strong>abdu1990</strong><br>
                    <em>Note: Works for both regular members and Sahakari members.</em>
                </div>
            </div>

            <!-- Registration Form -->
            <div class="form-container" id="register-form">


                <form id="registrationForm" method="POST" action="">
                    <div class="form-group">
                        <label for="name">Mahal Name</label>
                        <div class="input-with-icon">
                            <i class="fas fa-mosque"></i>
                            <input type="text" id="name" name="name" class="form-control"
                                placeholder="Green Valley Mahal" required>
                        </div>
                        <span class="error" id="nameError">Please enter mahal name (alphabets only)</span>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-map-marker-alt"></i>
                            <input type="text" id="address" name="address" class="form-control"
                                placeholder="Street, City, State" required>
                        </div>
                        <span class="error" id="addressError">Address should be at least 10 characters</span>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" class="form-control"
                                placeholder="info@example.com" required>
                        </div>
                        <span class="error" id="emailError">Please enter a valid email address</span>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <div class="input-with-icon">
                            <i class="fas fa-phone"></i>
                            <input type="tel" id="phone" name="phone" class="form-control" placeholder="9876543210"
                                maxlength="10" required>
                        </div>
                        <span class="error" id="phoneError">Phone number must be exactly 10 digits and start with
                            6,7,8,9</span>
                    </div>

                    <div class="form-group">
                        <label for="registration_no">Registration Number</label>
                        <div class="input-with-icon">
                            <i class="fas fa-id-card"></i>
                            <input type="text" id="registration_no" name="registration_no" class="form-control"
                                placeholder="GV12345" required>
                        </div>
                        <span class="error" id="regNoError">Please enter registration number (alphanumeric only)</span>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" class="form-control"
                                placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="passwordStrength"></div>
                        </div>
                        <span class="error" id="passwordError">Min 8 chars: A-Z, a-z, 0-9 & symbol</span>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                placeholder="Confirm your password" required>
                            <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="error" id="confirmPasswordError">Passwords do not match</span>
                    </div>


                    <!-- Compact Payment Container -->
                    <?php if ($hasPaymentDetails): ?>
                        <div class="payment-container">
                            <div class="payment-header">
                                <h3><i class="fas fa-credit-card"></i> Payment Details</h3>
                                <?php if (!empty($paymentDetails['registration_charge']) && $paymentDetails['registration_charge'] > 0): ?>
                                    <div class="payment-amount">
                                        <i class="fas fa-rupee-sign"></i>
                                        <?php echo htmlspecialchars(number_format($paymentDetails['registration_charge'], 2)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="payment-details">
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

                            <!-- Dynamic QR Code Container -->
                            <?php 
                                // Only show if we have UPI ID. Amount is optional (user can enter it), but here we want to prefill if reg charge exists.
                                if (!empty($paymentDetails['upi_id'])): 
                                    $upi_pa = $paymentDetails['upi_id'];
                                    // Use 'Mahal Registration' or site logo name as Payee Name
                                    $upi_pn = "Mahal Registration"; 
                                    $upi_am = (!empty($paymentDetails['registration_charge']) && $paymentDetails['registration_charge'] > 0) ? $paymentDetails['registration_charge'] : '0';
                                    
                                    // Prepare data for JS
                                    echo "<div id='upi-data' 
                                            data-pa='" . htmlspecialchars($upi_pa) . "' 
                                            data-pn='" . htmlspecialchars($upi_pn) . "' 
                                            data-am='" . htmlspecialchars($upi_am) . "' 
                                            style='display:none;'></div>";
                            ?>
                                <div class="payment-qr">
                                    <div id="qrcode" style="display: flex; justify-content: center; margin-bottom: 5px;"></div>
                                    <p>Scan QR to Pay <br>
                                    <small><?php echo htmlspecialchars($upi_pa); ?></small>
                                    </p>
                                </div>
                            <?php elseif (!empty($paymentDetails['qr_code_path'])): ?>
                                <!-- Fallback to static image if UPI ID is missing but image exists -->
                                <div class="payment-qr">
                                    <img src="<?php echo htmlspecialchars($paymentDetails['qr_code_path']); ?>"
                                        alt="Payment QR Code" onerror="this.style.display='none'">
                                    <p>Scan QR to Pay</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasPaymentDetails): ?>
                        <div class="payment-confirmation">
                            <div class="confirmation-checkbox">
                                <input type="checkbox" id="payment_confirmed" name="payment_confirmed" value="1">
                                <div class="confirmation-label">
                                    <label for="payment_confirmed">
                                        I confirm that I have <strong>completed the payment</strong> of
                                        <?php if (!empty($paymentDetails['registration_charge']) && $paymentDetails['registration_charge'] > 0): ?>
                                            <span class="amount-badge">
                                                <i class="fas fa-rupee-sign"></i>
                                                <?php echo htmlspecialchars(number_format($paymentDetails['registration_charge'], 2)); ?>
                                            </span>
                                        <?php else: ?>
                                            <strong>the registration fee</strong>
                                        <?php endif; ?>
                                        via the provided payment methods.
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <button type="submit" name="register" class="btn" id="registerButton" <?php echo $hasPaymentDetails ? 'disabled' : ''; ?>>
                        <i class="fas fa-user-plus"></i>
                        <?php echo $hasPaymentDetails ? 'Complete Payment First' : 'Create Account'; ?>
                    </button>

                    <div class="switch-form">
                        Already have an account? <a id="showLogin">Sign In</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast">
        <div class="toast-content">
            <span id="toastMessage"></span>
            <span class="close-toast" onclick="hideToast()">×</span>
        </div>
    </div>

    <script>
        // Toast functionality
        let toastTimer = null;

        function showToast(message, type = 'success', timeout = 4000) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            toastMessage.textContent = message;
            toast.className = '';
            toast.classList.add(type);
            toast.style.display = 'block';

            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(hideToast, timeout);
        }

        function hideToast() {
            const toast = document.getElementById('toast');
            toast.style.display = 'none';
            if (toastTimer) {
                clearTimeout(toastTimer);
                toastTimer = null;
            }
        }

        // Form switching
        

        document.getElementById('showLogin').addEventListener('click', function () {
            document.querySelectorAll('.form-container').forEach(form => {
                form.classList.remove('active');
            });
            document.getElementById('mahal-login').classList.add('active');
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('[data-tab="mahal-login"]').classList.add('active');
        });

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function () {
                const tabId = this.getAttribute('data-tab');

                document.querySelectorAll('.tab').forEach(t => {
                    t.classList.remove('active');
                });
                this.classList.add('active');

                document.querySelectorAll('.form-container').forEach(form => {
                    form.classList.remove('active');
                });
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Password visibility toggles
        function setupPasswordToggle(passwordId, toggleId) {
            const passwordInput = document.getElementById(passwordId);
            const toggleButton = document.getElementById(toggleId);

            if (passwordInput && toggleButton) {
                toggleButton.addEventListener('click', function () {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);

                    const icon = this.querySelector('i');
                    icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
                });
            }
        }

        setupPasswordToggle('login_password', 'toggleLoginPassword');
        setupPasswordToggle('member_password', 'toggleMemberPassword');
        setupPasswordToggle('password', 'togglePassword');
        setupPasswordToggle('confirm_password', 'toggleConfirmPassword');

        // Payment confirmation handling
        function handlePaymentConfirmation() {
            const paymentCheckbox = document.getElementById('payment_confirmed');
            const registerButton = document.getElementById('registerButton');

            if (!paymentCheckbox || !registerButton) return;

            function updateButtonState() {
                if (paymentCheckbox.checked) {
                    registerButton.disabled = false;
                    registerButton.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
                } else {
                    registerButton.disabled = true;
                    registerButton.innerHTML = '<i class="fas fa-lock"></i> Complete Payment First';
                }
            }

            // Initial state
            updateButtonState();

            // Update on checkbox change
            paymentCheckbox.addEventListener('change', updateButtonState);
        }

        // Initialize payment confirmation handler
        document.addEventListener('DOMContentLoaded', function () {
            handlePaymentConfirmation();
        });

        // Validation functions
        function validateName() {
            const el = document.getElementById('name');
            const err = document.getElementById('nameError');
            const v = el.value.trim();

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (!/^[A-Za-z\s]+$/.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Only alphabets allowed';
                err.classList.add('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        function validateAddress() {
            const el = document.getElementById('address');
            const err = document.getElementById('addressError');
            const v = el.value.trim();

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (v.length < 10) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Address should be at least 10 characters';
                err.classList.add('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        function validateEmailReg() {
            const el = document.getElementById('email');
            const err = document.getElementById('emailError');
            const v = el.value.trim();
            const rx = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (!rx.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Please enter a valid email address';
                err.classList.add('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        function validatePhone() {
            const el = document.getElementById('phone');
            const err = document.getElementById('phoneError');
            let v = el.value.trim().replace(/[^0-9]/g, '');
            el.value = v;

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (v.length > 0 && !/^[6-9]/.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Phone number must start with 6, 7, 8, or 9';
                err.classList.add('show');
                return false;
            } else if (v.length > 0 && v.length < 10) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Phone number must be exactly 10 digits';
                err.classList.add('show');
                return false;
            } else if (v.length === 10 && /^[6-9][0-9]{9}$/.test(v)) {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            } else {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            }
        }

        function validateRegNo() {
            const el = document.getElementById('registration_no');
            const err = document.getElementById('regNoError');
            let v = el.value.replace(/[^A-Za-z0-9]/g, '');
            el.value = v;

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (!/^[A-Za-z0-9]+$/.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Only alphabets and numbers are allowed';
                err.classList.add('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        function validatePasswordReg() {
            const el = document.getElementById('password');
            const err = document.getElementById('passwordError');
            const v = el.value;
            const rx = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%?&])[A-Za-z\d@$!%?&]{8,}$/;

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (!rx.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Min 8 chars: A-Z, a-z, 0-9 & symbol';
                err.classList.add('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        function validateConfirmPassword() {
            const p = document.getElementById('password');
            const c = document.getElementById('confirm_password');
            const err = document.getElementById('confirmPasswordError');
            const v = c.value, pv = p.value;

            if (v === '') {
                c.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (v !== pv) {
                c.classList.add('invalid');
                c.classList.remove('valid');
                err.textContent = 'Passwords do not match';
                err.classList.add('show');
                return false;
            } else {
                c.classList.add('valid');
                c.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        // Password strength indicator
        function updatePasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('passwordStrength');

            // Reset
            strengthBar.className = 'strength-bar';

            if (password.length === 0) {
                return;
            }

            // Calculate strength
            let strength = 0;

            // Length check
            if (password.length >= 8) strength += 1;

            // Contains lowercase
            if (/[a-z]/.test(password)) strength += 1;

            // Contains uppercase
            if (/[A-Z]/.test(password)) strength += 1;

            // Contains numbers
            if (/[0-9]/.test(password)) strength += 1;

            // Contains special characters
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;

            // Update visual indicator
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }

        // Member phone validation
        function validateMemberPhone() {
            const el = document.getElementById('member_phone');
            const err = document.getElementById('memberPhoneError');
            let v = el.value.trim().replace(/[^0-9]/g, '');
            el.value = v;

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else if (v.length > 0 && !/^[6-9]/.test(v)) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Phone number must start with 6, 7, 8, or 9';
                err.classList.add('show');
                return false;
            } else if (v.length > 0 && v.length < 10) {
                el.classList.add('invalid');
                el.classList.remove('valid');
                err.textContent = 'Phone number must be exactly 10 digits';
                err.classList.add('show');
                return false;
            } else if (v.length === 10 && /^[6-9][0-9]{9}$/.test(v)) {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            } else {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            }
        }

        // Member password validation
        function validateMemberPassword() {
            const el = document.getElementById('member_password');
            const err = document.getElementById('memberPasswordError');
            const v = el.value.trim();

            if (v === '') {
                el.classList.remove('valid', 'invalid');
                err.classList.remove('show');
                return false;
            } else {
                el.classList.add('valid');
                el.classList.remove('invalid');
                err.classList.remove('show');
                return true;
            }
        }

        // Login email validation
        function validateLoginEmail() {
            const el = document.getElementById('login_email');
            const err = document.getElementById('loginEmailError');
            const v = el.value.trim();
            const rx = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

            if (v === '') {
                err.classList.remove('show');
                return false;
            } else if (!rx.test(v)) {
                err.textContent = 'Please enter a valid email address';
                err.classList.add('show');
                return false;
            } else {
                err.classList.remove('show');
                return true;
            }
        }

        // Login password validation
        function validateLoginPassword() {
            const el = document.getElementById('login_password');
            const err = document.getElementById('loginPasswordError');
            const v = el.value;

            if (v === '') {
                err.textContent = 'Please enter your password';
                err.classList.add('show');
                return false;
            } else {
                err.classList.remove('show');
                return true;
            }
        }

        // Attach event listeners for real-time validation
        document.getElementById('name').addEventListener('input', validateName);
        document.getElementById('address').addEventListener('input', validateAddress);
        document.getElementById('email').addEventListener('input', validateEmailReg);
        document.getElementById('phone').addEventListener('input', validatePhone);
        document.getElementById('registration_no').addEventListener('input', validateRegNo);
        document.getElementById('password').addEventListener('input', function () {
            validatePasswordReg();
            validateConfirmPassword();
            updatePasswordStrength();
        });
        document.getElementById('confirm_password').addEventListener('input', validateConfirmPassword);
        document.getElementById('member_phone').addEventListener('input', validateMemberPhone);
        document.getElementById('member_password').addEventListener('input', validateMemberPassword);
        document.getElementById('login_email').addEventListener('input', validateLoginEmail);
        document.getElementById('login_password').addEventListener('input', validateLoginPassword);

        // Form submissions (AJAX)
        document.getElementById('loginForm').addEventListener('submit', function (e) {
            e.preventDefault();
            let ok = true;

            const em = document.getElementById('login_email').value.trim();
            const rx = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            if (!rx.test(em)) {
                document.getElementById('loginEmailError').classList.add('show');
                ok = false;
            }

            const pw = document.getElementById('login_password').value;
            if (pw === '') {
                document.getElementById('loginPasswordError').classList.add('show');
                ok = false;
            }

            if (!ok) return;

            const formData = new FormData(this);
            formData.append('login_ajax', '1');

            const submitBtn = this.querySelector('.btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';

            fetch('', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';

                    if (data && data.success) {
                        showToast(data.message || 'Login successful', 'success', 2000);
                        setTimeout(() => {
                            window.location.href = data.redirect || 'dashboard.php';
                        }, 800);
                    } else {
                        showToast((data && data.message) ? data.message : 'Login failed', 'error', 5000);
                    }
                })
                .catch(err => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';
                    console.error('Login AJAX error:', err);
                    showToast('Network or server error during login', 'error', 5000);
                });
        });

        document.getElementById('memberLoginForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const v1 = validateMemberPhone();
            const v2 = validateMemberPassword();

            if (!(v1 && v2)) {
                showToast('Please fix the validation errors before submitting', 'error', 5000);
                return;
            }

            const formData = new FormData(this);
            formData.append('member_login_ajax', '1');

            const submitBtn = this.querySelector('.btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';

            fetch('', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Member Login';

                    if (data && data.success) {
                        showToast(data.message || 'Login successful', 'success', 2000);
                        setTimeout(() => {
                            window.location.href = data.redirect || 'member_dashboard.php';
                        }, 800);
                    } else {
                        showToast((data && data.message) ? data.message : 'Login failed', 'error', 5000);
                    }
                })
                .catch(err => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Member Login';
                    console.error('Member login AJAX error:', err);
                    showToast('Network or server error during login', 'error', 5000);
                });
        });

        document.getElementById('registrationForm').addEventListener('submit', function (e) {
            e.preventDefault();

            // Check payment confirmation if payment details exist
            const paymentCheckbox = document.getElementById('payment_confirmed');
            if (paymentCheckbox && !paymentCheckbox.checked) {
                showToast('Please confirm that you have completed the payment by checking the box.', 'error', 5000);
                return;
            }

            const v1 = validateName(), v2 = validateAddress(), v3 = validateEmailReg(),
                v4 = validatePhone(), v5 = validateRegNo(), v6 = validatePasswordReg(),
                v7 = validateConfirmPassword();

            if (!(v1 && v2 && v3 && v4 && v5 && v6 && v7)) {
                showToast('Please fix the validation errors before submitting', 'error', 5000);
                return;
            }

            const formData = new FormData(this);
            formData.append('register_ajax', '1');

            const submitBtn = this.querySelector('.btn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';

            fetch('', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';

                    if (data && data.success) {
                        showToast(data.message || 'Registration successful', 'success', 3000);
                        this.reset();
                        document.querySelectorAll('#registrationForm input').forEach(i => i.classList.remove('valid', 'invalid'));
                        document.getElementById('passwordStrength').className = 'strength-bar';

                        // Reset payment confirmation
                        const paymentCheckbox = document.getElementById('payment_confirmed');
                        if (paymentCheckbox) {
                            paymentCheckbox.checked = false;
                            handlePaymentConfirmation();
                        }

                        setTimeout(() => {
                            document.querySelectorAll('.form-container').forEach(form => {
                                form.classList.remove('active');
                            });
                            document.getElementById('mahal-login').classList.add('active');
                            document.querySelectorAll('.tab').forEach(tab => {
                                tab.classList.remove('active');
                            });
                            document.querySelector('[data-tab="mahal-login"]').classList.add('active');
                        }, 800);
                    } else {
                        showToast((data && data.message) ? data.message : 'Registration failed', 'error', 5000);
                    }
                })
                .catch(err => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
                    console.error('Registration AJAX error:', err);
                    showToast('Network or server error. Please try again later.', 'error', 5000);
                });
        });
    </script>
    <script>
        // ... (existing scripts) ...

        // Generate QR Code if data exists
        document.addEventListener('DOMContentLoaded', function() {
            const upiData = document.getElementById('upi-data');
            if (upiData) {
                const pa = upiData.getAttribute('data-pa');
                const pn = upiData.getAttribute('data-pn');
                const am = upiData.getAttribute('data-am');
                
                // UPI URL Format: upi://pay?pa=UPI_ID&pn=NAME&am=AMOUNT&cu=INR
                // &tn=NOTE (optional)
                
                let upiUrl = `upi://pay?pa=${encodeURIComponent(pa)}&pn=${encodeURIComponent(pn)}&cu=INR`;
                if (am && parseFloat(am) > 0) {
                    upiUrl += `&am=${encodeURIComponent(am)}`;
                }
                
                // Add a note/transaction note if desired
                upiUrl += `&tn=Registration Fee`;

                console.log("Generating QR for:", upiUrl); // Debugging

                const qrContainer = document.getElementById('qrcode');
                if (qrContainer) {
                    new QRCode(qrContainer, {
                        text: upiUrl,
                        width: 128,
                        height: 128,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.M
                    });
                }
            }
        });
    </script>
</body>

</html>