<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//asset_management.php
require_once __DIR__ . '/session_bootstrap.php';

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
  header("Location: index.php");
  exit();
}

// Include the centralized database connection
require_once 'db_connection.php';

// Get database connection
$db_result = get_db_connection();
if (isset($db_result['error'])) {
  die("Database connection failed: " . $db_result['error']);
}
// Use mysqli connection (rename for clarity)
$conn = $db_result['conn'];

// ENSURE SCHEMA: Make sure transactions table has asset columns



// Get logged-in user details
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT name, address, registration_no, email FROM register WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows == 0) {
  echo "<script>alert('Unable to fetch user details. Please log in again.'); window.location.href='index.php';</script>";
  exit();
}

$stmt->bind_result($user_name, $user_address, $registration_no, $user_email);
$stmt->fetch();
$stmt->close();

// Use the logged-in user's id as mahal_id
$mahal_id = $user_id;

// Create $mahal array for consistency with dashboard
$mahal = [
  'name' => $user_name,
  'address' => $user_address,
  'registration_no' => $registration_no,
  'email' => $user_email
];

// Define logo path
$logo_path = "logo.jpeg";

// ============================================================================
// FUNCTION DEFINITIONS - Must be defined BEFORE they are called
// ============================================================================

// Function to create asset tables if they don't exist
function createAssetTablesIfNotExists($conn, $mahal_id_param)
{

  try {
    // Assets table - UPDATED: Added rental_status column
    $conn->query("CREATE TABLE IF NOT EXISTS `assets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mahal_id` int(11) NOT NULL,
            `asset_code` varchar(50) NOT NULL,
            `name` varchar(255) NOT NULL,
            `category_id` int(11) NOT NULL,
            `description` text DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL,
            `acquisition_date` date NOT NULL,
            `vendor_donor` varchar(255) DEFAULT NULL,
            `purchase_cost` decimal(15,2) DEFAULT 0.00,
            `current_value` decimal(15,2) DEFAULT 0.00,
            `taxable_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Taxable amount for this asset',
            `rental_status` enum('rental','non_rental') NOT NULL DEFAULT 'non_rental' COMMENT 'Rental or Non-Rental',
            `condition_status` enum('excellent','good','fair','needs_repair','out_of_service') NOT NULL DEFAULT 'good',
            `status` enum('active','inactive','disposed','lost') NOT NULL DEFAULT 'active',
            `assigned_to` int(11) DEFAULT NULL COMMENT 'staff_id',
            `maintenance_frequency` enum('weekly','monthly','quarterly','semi_annual','annual','as_needed') DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_asset_code` (`mahal_id`,`asset_code`),
            KEY `idx_mahal_id` (`mahal_id`),
            KEY `idx_category_id` (`category_id`),
            KEY `idx_status` (`status`),
            KEY `idx_rental_status` (`rental_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Asset categories (normalized)
    $conn->query("CREATE TABLE IF NOT EXISTS `asset_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mahal_id` int(11) NOT NULL,
            `category_name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `status` enum('active','inactive') DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_category` (`mahal_id`,`category_name`),
            KEY `idx_mahal_id` (`mahal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Asset maintenance (normalized)
    $conn->query("CREATE TABLE IF NOT EXISTS `asset_maintenance` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mahal_id` int(11) NOT NULL,
            `asset_id` int(11) NOT NULL,
            `maintenance_type` enum('routine_inspection','cleaning','repair','replacement','safety_check','servicing','calibration') NOT NULL,
            `description` text DEFAULT NULL,
            `scheduled_date` date NOT NULL,
            `due_date` date NOT NULL,
            `completed_date` date DEFAULT NULL,
            `priority` enum('low','normal','high','critical') NOT NULL DEFAULT 'normal',
            `assigned_to` int(11) DEFAULT NULL COMMENT 'staff_id',
            `estimated_cost` decimal(10,2) DEFAULT 0.00,
            `actual_cost` decimal(10,2) DEFAULT 0.00,
            `status` enum('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            `notes` text DEFAULT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_mahal_id` (`mahal_id`),
            KEY `idx_asset_id` (`asset_id`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Asset bookings (normalized) - UPDATED SCHEMA
    $conn->query("CREATE TABLE IF NOT EXISTS `asset_bookings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mahal_id` int(11) NOT NULL,
            `asset_id` int(11) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `booking_amount` decimal(10,2) DEFAULT 0.00,
            `booked_by` varchar(255) NOT NULL COMMENT 'member_id or name',
            `booked_for` int(11) DEFAULT NULL COMMENT 'member_id if applicable',
            `purpose` varchar(500) NOT NULL,
            `requirements` text DEFAULT NULL,
            `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `approved_by` int(11) DEFAULT NULL,
            `approved_at` timestamp NULL DEFAULT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_mahal_id` (`mahal_id`),
            KEY `idx_asset_id` (`asset_id`),
            KEY `idx_dates` (`start_date`, `end_date`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Check if old schema exists and migrate if needed
    $col_check = $conn->query("SHOW COLUMNS FROM asset_bookings LIKE 'booking_date'");
    if ($col_check && $col_check->num_rows > 0) {
      // Old schema exists, migrate to new schema
      $conn->query("ALTER TABLE asset_bookings 
                ADD COLUMN `start_date` date NOT NULL AFTER `asset_id`,
                ADD COLUMN `end_date` date NOT NULL AFTER `start_date`,
                ADD COLUMN `booking_amount` decimal(10,2) DEFAULT 0.00 AFTER `end_date`");

      // Migrate data: use booking_date for both start_date and end_date
      $conn->query("UPDATE asset_bookings SET start_date = booking_date, end_date = booking_date");

      // Drop old columns
      $conn->query("ALTER TABLE asset_bookings 
                DROP COLUMN booking_date,
                DROP COLUMN start_time,
                DROP COLUMN end_time,
                DROP COLUMN attendees");
    }

    // Asset documents (normalized)
    $conn->query("CREATE TABLE IF NOT EXISTS `asset_documents` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mahal_id` int(11) NOT NULL,
            `asset_id` int(11) NOT NULL,
            `document_type` enum('purchase_invoice','warranty','manual','insurance','permit','contract','title_deed','other') NOT NULL,
            `document_name` varchar(255) NOT NULL,
            `file_name` varchar(500) DEFAULT NULL,
            `file_path` varchar(500) DEFAULT NULL,
            `file_size` int(11) DEFAULT NULL,
            `expiry_date` date DEFAULT NULL,
            `description` text DEFAULT NULL,
            `uploaded_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_mahal_id` (`mahal_id`),
            KEY `idx_asset_id` (`asset_id`),
            KEY `idx_document_type` (`document_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert default categories if none exist
    $result = $conn->query("SELECT COUNT(*) as cnt FROM asset_categories WHERE mahal_id = $mahal_id_param");
    $row = $result->fetch_assoc();
    $category_count = $row['cnt'];

    if ($category_count == 0) {
      $default_categories = [
        ['Land & Building', 'Properties and buildings'],
        ['Prayer Hall Equipment', 'Audio system, carpets, AC'],
        ['Educational Materials', 'Books, whiteboards, chairs'],
        ['Office Equipment', 'Computers, printers, furniture'],
        ['Kitchen Equipment', 'Utensils, stove, refrigerator'],
        ['Cleaning Equipment', 'Vacuum, mops, cleaning supplies'],
        ['Vehicle', 'Transportation vehicles'],
        ['Other Assets', 'Miscellaneous assets']
      ];

      foreach ($default_categories as $category) {
        $stmt = $conn->prepare("INSERT INTO asset_categories (mahal_id, category_name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $mahal_id_param, $category[0], $category[1]);
        $stmt->execute();
        $stmt->close();
      }
    }

  } catch (Exception $e) {
    error_log("Table creation error: " . $e->getMessage());
  }
}

// Function to handle form submissions
function handleAssetFormSubmit($conn, $user_id, $mahal_id, $post_data)
{
  try {
    switch ($post_data['action']) {
      case 'add_asset':
        // Get quantity, default to 1
        $quantity = isset($post_data['quantity']) ? max(1, intval($post_data['quantity'])) : 1;
        $asset_type = $post_data['asset_type'] ?? 'existing';
        $success_count = 0;
        $last_error = '';
        $result = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE mahal_id = $mahal_id");
        $row = $result->fetch_assoc();
        $existing_asset_count = $row['cnt'];
        if ($asset_type === 'new_purchase') {
          $acquisition_date = $post_data['acquisition_date'];
          $vendor_donor = $post_data['vendor_donor'] ?? '';
          $purchase_cost = floatval($post_data['purchase_cost']);
          $current_value = $purchase_cost; // Set current value equal to purchase cost
          $taxable_amount = floatval($post_data['taxable_amount'] ?? 0.00);
        } else {
          // For existing assets
          $acquisition_date = date('Y-m-d'); // Use todays date
          $vendor_donor = '';
          $purchase_cost = 0.00;
          $current_value = 0.00;
          $taxable_amount = floatval($post_data['taxable_amount'] ?? 0.00);
        }
        $rental_status = $post_data['rental_status'] ?? 'non_rental';
        $description = $post_data['description'] ?? '';
        $asset_name = $post_data['asset_name'];

        // Set default values for removed fields
        $location = '';
        $assigned_to = NULL;
        $maintenance_frequency = NULL;
        $notes = '';
        $condition_status = 'good'; // Default condition

        $stmt = $conn->prepare("INSERT INTO assets (mahal_id, asset_code, name, category_id, description, location, acquisition_date, vendor_donor, purchase_cost, current_value, taxable_amount, rental_status, condition_status, maintenance_frequency, assigned_to, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Variable to bind for asset code, will be updated in loop
        $current_asset_code = '';
        $stmt->bind_param(
          "ississsdddssssisi",
          $mahal_id,
          $current_asset_code,
          $asset_name,
          $post_data['category_id'],
          $description,
          $location,
          $acquisition_date,
          $vendor_donor,
          $purchase_cost,
          $current_value,
          $taxable_amount,
          $rental_status,
          $condition_status,
          $maintenance_frequency,
          $assigned_to,
          $notes,
          $user_id
        );

        // Loop to insert assets
        for ($i = 0; $i < $quantity; $i++) {
          $next_asset_number = $existing_asset_count + 1 + $i;
          $current_asset_code = "AST" . str_pad($mahal_id, 3, '0', STR_PAD_LEFT) . str_pad($next_asset_number, 4, '0', STR_PAD_LEFT);

          if ($stmt->execute()) {
            $success_count++;
            $new_asset_id = $conn->insert_id;

            // AUTOMATIC: Create Finance Transaction ONLY for New Purchase
            if ($asset_type === 'new_purchase' && $purchase_cost > 0) {
              $trans_date = $acquisition_date;
              $trans_year = date('Y', strtotime($trans_date));

              // 1. Calculate next Voucher Number
              $rc_stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND YEAR(transaction_date) = ?");
              $rc_val = 0;
              $rc_count = 0;
              if ($rc_stmt) {
                $rc_stmt->bind_param("ii", $user_id, $trans_year);
                $rc_stmt->execute();
                $rc_stmt->bind_result($rc_count);
                if ($rc_stmt->fetch()) {
                  $rc_val = $rc_count;
                }
                $rc_stmt->close();
              }
              $next_rc_num = $rc_val + 1;
              $receipt_no = "V" . $next_rc_num . "/" . $trans_year; // V for Expense

              // 2. Insert Transaction
              $desc = "Asset Purchase: " . $asset_name . " (" . $current_asset_code . ")";

              // Use vendor_donor as paid_to
              $paid_to = !empty($vendor_donor) ? $vendor_donor : null;

              $t_stmt = $conn->prepare("INSERT INTO transactions (user_id, transaction_date, type, category, amount, description, payment_mode, asset_id, receipt_no, donor_details) VALUES (?, ?, 'EXPENSE', 'PURCHASE', ?, ?, 'CASH', ?, ?, ?)");
              if ($t_stmt) {
                $t_stmt->bind_param("isdsiss", $user_id, $trans_date, $purchase_cost, $desc, $new_asset_id, $receipt_no, $paid_to);
                if (!$t_stmt->execute()) {
                  error_log("Failed to insert asset transaction: " . $t_stmt->error);
                }
                $t_stmt->close();
              } else {
                error_log("Failed to prepare asset transaction insert: " . $conn->error);
              }
            }

          } else {
            $last_error = $stmt->error;
          }
        }

        $stmt->close();

        if ($success_count > 0) {
          $msg = $success_count . ($success_count == 1 ? " Asset" : " Assets") . " added successfully!";
          echo "<script>
            alert('$msg');
            window.location.href = window.location.href;
        </script>";
        } else {
          echo "<script>alert('Error saving asset(s): " . addslashes($last_error) . "');</script>";
        }
        exit;


      case 'schedule_maintenance':
        $stmt = $conn->prepare("INSERT INTO asset_maintenance (mahal_id, asset_id, maintenance_type, description, scheduled_date, due_date, priority, assigned_to, estimated_cost, created_by) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)");

        $assigned_to = !empty($post_data['assigned_to']) ? intval($post_data['assigned_to']) : NULL;
        $estimated_cost = !empty($post_data['estimated_cost']) ? floatval($post_data['estimated_cost']) : 0.00;
        $description = $post_data['description'] ?? '';

        $stmt->bind_param(
          "iissssidi",
          $mahal_id,
          $post_data['asset_id'],
          $post_data['maintenance_type'],
          $description,
          $post_data['due_date'],
          $post_data['priority'],
          $assigned_to,
          $estimated_cost,
          $user_id
        );

        if ($stmt->execute()) {
          $maintenance_id = $conn->insert_id;
          $stmt->close();

          echo "<script>
                        alert('Maintenance scheduled successfully!');
                        window.location.href = window.location.href;
                    </script>";
        } else {
          echo "<script>alert('Error scheduling maintenance: " . addslashes($conn->error) . "');</script>";
        }
        exit;

      case 'book_asset':
        // Get asset_id from the form
        $asset_id = intval($post_data['asset_id'] ?? 0);
        $start_date = $post_data['start_date'] ?? '';
        $end_date = $post_data['end_date'] ?? '';

        // Validate inputs
        if (!$asset_id || !$start_date || !$end_date) {
          echo "<script>alert('Error: Please fill all required fields!');</script>";
          exit;
        }

        // Check if end date is after start date
        if (strtotime($end_date) < strtotime($start_date)) {
          echo "<script>alert('Error: End date must be after start date!');</script>";
          exit;
        }

        // Check for booking conflicts (date range overlap)
        $stmt = $conn->prepare("SELECT COUNT(*) as conflict_count FROM asset_bookings 
                    WHERE asset_id = ? 
                    AND status = 'approved' 
                    AND (
                        (start_date <= ? AND end_date >= ?) OR
                        (start_date <= ? AND end_date >= ?) OR
                        (start_date >= ? AND end_date <= ?)
                    )");

        $stmt->bind_param(
          "issssss",
          $asset_id,
          $start_date,
          $start_date,
          $end_date,
          $end_date,
          $start_date,
          $end_date
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $conflict_count = $row['conflict_count'];
        $stmt->close();

        if ($conflict_count > 0) {
          // Try to find another asset with the same name that is available
          $found_alternative = false;

          // Get asset name
          $stmt_name = $conn->prepare("SELECT name FROM assets WHERE id = ?");
          $stmt_name->bind_param("i", $asset_id);
          $stmt_name->execute();
          $res_name = $stmt_name->get_result();
          if ($row_name = $res_name->fetch_assoc()) {
            $asset_name = $row_name['name'];
            $stmt_name->close();

            // Find all other assets with same name
            $stmt_alt = $conn->prepare("SELECT id FROM assets WHERE name = ? AND id != ? AND rental_status = 'rental' AND status = 'active' AND mahal_id = ?");
            $stmt_alt->bind_param("sii", $asset_name, $asset_id, $mahal_id);
            $stmt_alt->execute();
            $res_alt = $stmt_alt->get_result();

            while ($row_alt = $res_alt->fetch_assoc()) {
              $alt_id = $row_alt['id'];

              // Check availability for this alternative
              $stmt_check = $conn->prepare("SELECT COUNT(*) as cnt FROM asset_bookings 
                                WHERE asset_id = ? 
                                AND status = 'approved' 
                                AND (
                                    (start_date <= ? AND end_date >= ?) OR
                                    (start_date <= ? AND end_date >= ?) OR
                                    (start_date >= ? AND end_date <= ?)
                                )");
              $stmt_check->bind_param("issssss", $alt_id, $start_date, $start_date, $end_date, $end_date, $start_date, $end_date);
              $stmt_check->execute();
              $res_check = $stmt_check->get_result();
              $row_check = $res_check->fetch_assoc();
              $stmt_check->close();

              if ($row_check['cnt'] == 0) {
                // Found available alternative!
                $asset_id = $alt_id;
                $found_alternative = true;
                break;
              }
            }
            $stmt_alt->close();
          } else {
            $stmt_name->close();
          }

          if (!$found_alternative) {
            echo "<script>alert('Error: All items of this type are fully booked for the selected date range!');</script>";
            exit;
          }
        }

        $stmt = $conn->prepare("INSERT INTO asset_bookings (mahal_id, asset_id, start_date, end_date, booking_amount, booked_by, booked_for, purpose, requirements, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $booked_for = !empty($post_data['member_id']) ? intval($post_data['member_id']) : NULL;
        $booking_amount = !empty($post_data['booking_amount']) ? floatval($post_data['booking_amount']) : 0.00;
        $requirements = $post_data['requirements'] ?? '';

        $stmt->bind_param(
          "iissdsissi",
          $mahal_id,
          $asset_id,
          $start_date,
          $end_date,
          $booking_amount,
          $post_data['booked_by'],
          $booked_for,
          $post_data['purpose'],
          $requirements,
          $user_id
        );

        if ($stmt->execute()) {
          $booking_id = $conn->insert_id;
          $stmt->close();

          echo "<script>
                        alert('Booking request submitted successfully!');
                        window.location.href = window.location.href;
                    </script>";
        } else {
          echo "<script>alert('Error creating booking: " . addslashes($conn->error) . "');</script>";
        }
        exit;

      case 'add_category':
        $stmt = $conn->prepare("INSERT INTO asset_categories (mahal_id, category_name, description) VALUES (?, ?, ?)");
        $category_description = $post_data['category_description'] ?? '';
        $stmt->bind_param("iss", $mahal_id, $post_data['category_name'], $category_description);

        if ($stmt->execute()) {
          $category_id = $conn->insert_id;
          $stmt->close();

          echo "<script>
                        alert('Category added successfully!');
                        window.location.href = window.location.href;
                    </script>";
        } else {
          echo "<script>alert('Error adding category: " . addslashes($conn->error) . "');</script>";
        }
        exit;

      case 'update_asset':
        $asset_id = intval($post_data['asset_id']);
        $taxable_amount = floatval($post_data['taxable_amount'] ?? 0.00);
        $rental_status = $post_data['rental_status'] ?? 'non_rental';

        $stmt = $conn->prepare("UPDATE assets SET name = ?, category_id = ?, acquisition_date = ?, vendor_donor = ?, purchase_cost = ?, current_value = ?, taxable_amount = ?, rental_status = ?, condition_status = ?, status = ?, location = ?, assigned_to = ?, maintenance_frequency = ?, description = ?, notes = ? WHERE id = ? AND mahal_id = ?");

        $assigned_to = !empty($post_data['assigned_to']) ? intval($post_data['assigned_to']) : NULL;
        $maintenance_frequency = !empty($post_data['maintenance_frequency']) ? $post_data['maintenance_frequency'] : NULL;
        $description = $post_data['description'] ?? '';
        $location = $post_data['location'] ?? '';
        $vendor_donor = $post_data['vendor_donor'] ?? '';
        $notes = $post_data['notes'] ?? '';
        $asset_status = $post_data['asset_status'] ?? 'active';

        $stmt->bind_param(
          "sisssddsssssissii",
          $post_data['asset_name'],
          $post_data['category_id'],
          $post_data['acquisition_date'],
          $vendor_donor,
          $post_data['purchase_cost'],
          $post_data['current_value'],
          $taxable_amount,
          $rental_status,
          $post_data['condition_status'],
          $asset_status,
          $location,
          $assigned_to,
          $maintenance_frequency,
          $description,
          $notes,
          $asset_id,
          $mahal_id
        );

        if ($stmt->execute()) {
          $stmt->close();

          echo "<script>
                        alert('Asset updated successfully!');
                        window.location.href = window.location.href;
                    </script>";
        } else {
          echo "<script>alert('Error updating asset: " . addslashes($conn->error) . "');</script>";
        }
        exit;
    }
  } catch (Exception $e) {
    echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
  }
}

// ============================================================================
// MAIN EXECUTION CODE
// ============================================================================

// Initialize statistics
$total_assets = 0;
$total_value = 0;
$active_bookings = 0;
$total_tax = 0;
$rental_assets = 0;
$non_rental_assets = 0;

try {
  // Check if asset tables exist, create if not
  createAssetTablesIfNotExists($conn, $mahal_id);

  // Get total assets count
  $result = $conn->query("SELECT COUNT(*) as cnt FROM assets WHERE mahal_id = $mahal_id AND status = 'active'");
  if ($result) {
    $row = $result->fetch_assoc();
    $total_assets = intval($row['cnt']);
  }

  // Get total value
  $result = $conn->query("SELECT COALESCE(SUM(current_value), 0) as total FROM assets WHERE mahal_id = $mahal_id AND status = 'active'");
  if ($result) {
    $row = $result->fetch_assoc();
    $total_value = floatval($row['total']);
  }

  // Get active bookings count (current and future bookings)
  $result = $conn->query("SELECT COUNT(*) as cnt FROM asset_bookings WHERE mahal_id = $mahal_id AND status = 'approved' AND end_date >= CURDATE()");
  if ($result) {
    $row = $result->fetch_assoc();
    $active_bookings = intval($row['cnt']);
  }

  // Get total taxable amount - Calculate as total of taxable_amount column
  $result = $conn->query("SELECT COALESCE(SUM(taxable_amount), 0) as total 
                           FROM assets 
                           WHERE mahal_id = $mahal_id AND status = 'active'");
  if ($result) {
    $row = $result->fetch_assoc();
    $total_tax = floatval($row['total']);
  }

  // Get rental vs non-rental assets count
  $result = $conn->query("SELECT rental_status, COUNT(*) as cnt FROM assets WHERE mahal_id = $mahal_id AND status = 'active' GROUP BY rental_status");
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      if ($row['rental_status'] == 'rental') {
        $rental_assets = intval($row['cnt']);
      } else {
        $non_rental_assets = intval($row['cnt']);
      }
    }
  }

} catch (Exception $e) {
  error_log("Asset Management Error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  handleAssetFormSubmit($conn, $user_id, $mahal_id, $_POST);
}

// Fetch data for dropdowns
$categories_list = [];
$stmt = $conn->prepare("SELECT id, category_name FROM asset_categories WHERE mahal_id = ? AND status = 'active' ORDER BY category_name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $categories_list[] = $row;
}
$stmt->close();

// Get category counts
$categories_with_stats = [];
$stmt = $conn->prepare("
    SELECT 
        c.id, 
        c.category_name, 
        c.description,
        COUNT(a.id) as asset_count,
        COALESCE(SUM(a.current_value), 0) as total_value
    FROM asset_categories c 
    LEFT JOIN assets a ON c.id = a.category_id AND a.status = 'active'
    WHERE c.mahal_id = ? 
    GROUP BY c.id
    ORDER BY c.category_name
");

$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $categories_with_stats[] = $row;
}
$stmt->close();

// Fetch all assets
$assets_list = [];
$stmt = $conn->prepare("SELECT a.id, a.asset_code, a.name, ac.category_name, a.status, a.rental_status FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.mahal_id = ? ORDER BY a.name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $assets_list[] = $row;
}
$stmt->close();

// Fetch rental assets for booking modal - Grouped by name for Bulk Booking
$rental_assets_list = [];
$stmt = $conn->prepare("SELECT MIN(a.id) as id, a.name, ac.category_name, COUNT(*) as asset_count 
                      FROM assets a 
                      JOIN asset_categories ac ON a.category_id = ac.id 
                      WHERE a.mahal_id = ? AND a.rental_status = 'rental' AND a.status = 'active' 
                      GROUP BY a.name 
                      ORDER BY a.name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $rental_assets_list[] = $row;
}
$stmt->close();

// Fetch staff
$staff_list = [];
$stmt = $conn->prepare("SELECT id, name, staff_id FROM staff WHERE mahal_id = ? AND salary_status = 'active' ORDER BY name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $staff_list[] = $row;
}
$stmt->close();

// Fetch members
$members_list = [];
try {
  // First, check what columns exist in members table
  $columns_result = $conn->query("DESCRIBE members");
  $has_status = false;

  if ($columns_result) {
    while ($col = $columns_result->fetch_assoc()) {
      if ($col['Field'] == 'status') {
        $has_status = true;
        break;
      }
    }
  }

  // Build query based on whether status column exists
  if ($has_status) {
    $stmt = $conn->prepare("SELECT id, head_name, member_number FROM members WHERE mahal_id = ? AND status = 'active' ORDER BY head_name");
  } else {
    $stmt = $conn->prepare("SELECT id, head_name, member_number FROM members WHERE mahal_id = ? ORDER BY head_name");
  }

  $stmt->bind_param("i", $mahal_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $members_list[] = $row;
  }
  $stmt->close();
} catch (Exception $e) {
  error_log("Error fetching members: " . $e->getMessage());
  // If members table doesn't exist or has issues, leave array empty
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Asset Management - <?php echo htmlspecialchars($mahal['name']); ?></title>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap"
    rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    /* Your existing CSS styles for asset management */
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
      --info: #3498db;
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

    /* Sidebar styles */
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

    /* Top Row */
    .top-row {
      display: flex;
      gap: 24px;
      padding: 24px;
      align-items: flex-start;
      flex-wrap: wrap;
    }

    /* Logo Row */
    .logo-row {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
      padding: 20px;
      background: linear-gradient(135deg, var(--card), var(--card-alt));
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .logo-row::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 6px;
      height: 100%;
      background: linear-gradient(135deg, var(--primary), var(--secondary), var(--accent));
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

    .logo-container {
      display: flex;
      align-items: center;
      gap: 16px;
      flex: 1;
    }

    .logo-row img {
      width: 64px;
      height: 64px;
      object-fit: contain;
      border-radius: var(--radius-sm);
      box-shadow: var(--shadow);
      border: 2px solid var(--border);
      background: white;
      padding: 4px;
    }

    .name-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .name-ar {
      font-size: 24px;
      font-weight: 800;
      font-family: 'Amiri', 'Scheherazade New', serif;
      color: var(--text);
      background: linear-gradient(135deg, var(--primary), var(--secondary), var(--accent));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      line-height: 1.2;
    }

    .name-subtitle {
      font-size: 14px;
      color: var(--text-light);
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .name-subtitle i {
      color: var(--secondary);
      font-size: 12px;
    }

    /* Asset Management specific styles */
    .main-left {
      flex: 1;
      min-width: 300px;
    }

    /* Asset Quick Access Cards */
    .quick-access-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-bottom: 30px;
    }

    .quick-access-card {
      background: var(--card);
      border-radius: var(--radius);
      padding: 25px;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      cursor: pointer;
      position: relative;
      overflow: hidden;
    }

    .quick-access-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .quick-access-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-lg);
    }

    .quick-access-icon {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 15px;
      font-size: 28px;
      color: white;
    }

    .quick-access-card.inventory .quick-access-icon {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .quick-access-card.bookings .quick-access-icon {
      background: linear-gradient(135deg, var(--secondary), #4aa08d);
    }

    .quick-access-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 8px;
    }

    .quick-access-desc {
      font-size: 14px;
      color: var(--text-light);
      line-height: 1.5;
    }

    /* Stats Cards - FIXED VERSION */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      margin-bottom: 24px;
    }


    .stat-card {
      background: var(--card);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: var(--shadow);
      transition: var(--transition);
      border: 1px solid var(--border);
      display: flex;
      align-items: flex-start;
      gap: 16px;
      position: relative;
      overflow: hidden;
      min-height: 120px;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-lg);
    }


    .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: white;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .stat-card:nth-child(1) .stat-icon {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .stat-card:nth-child(2) .stat-icon {
      background: linear-gradient(135deg, var(--success), #229954);
    }

    .stat-card:nth-child(3) .stat-icon {
      background: linear-gradient(135deg, var(--info), #2980b9);
    }

    .stat-card:nth-child(4) .stat-icon {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .stat-details {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
    }

    .stat-details h3 {
      font-size: 28px;
      font-weight: 700;
      color: var(--text);
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .stat-details p {
      font-size: 13px;
      color: var(--text-light);
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin: 0;
    }

    /* Color variants for stat icons */
    .stat-icon.primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .stat-icon.success {
      background: linear-gradient(135deg, var(--success), #229954);
    }

    .stat-icon.info {
      background: linear-gradient(135deg, var(--info), #2980b9);
    }

    .stat-icon.warning {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .stat-icon.danger {
      background: linear-gradient(135deg, var(--error), #c0392b);
    }

    .stat-value {
      font-size: 24px;
      font-weight: 700;
      color: var(--text);
      line-height: 1;
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 12px;
      color: var(--text-light);
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Content Sections */
    .content-section {
      display: none;
      padding: 24px;
      animation: fadeIn 0.3s ease;
    }

    .content-section.active {
      display: block;
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

    /* Card Styles */
    .card {
      background: var(--card);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-bottom: 24px;
    }

    .card-header {
      padding: 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      color: white;
    }

    .card-header h3 {
      font-size: 18px;
      font-weight: 600;
    }

    .card-body {
      padding: 20px;
    }

    /* Table Styles */
    .table-container {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    table th,
    table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }

    table th {
      background: var(--card-alt);
      font-weight: 600;
      color: var(--text);
    }

    table tr:hover {
      background: var(--card-alt);
    }

    /* Button Styles */
    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: var(--radius-sm);
      cursor: pointer;
      font-size: 14px;
      font-weight: 500;
      transition: var(--transition);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success), #229954);
      color: white;
    }

    .btn-success:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning), #d97706);
      color: white;
    }

    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
    }

    .btn-info {
      background: linear-gradient(135deg, var(--primary-light), #4a8bc6);
      color: white;
    }

    .btn-info:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(74, 139, 198, 0.3);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--error), #c0392b);
      color: white;
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(231, 76, 60, 0.3);
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
    }

    /* Badge Styles */
    .badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
    }

    .badge-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
    }

    .badge-warning {
      background: rgba(245, 158, 11, 0.15);
      color: var(--warning);
    }

    .badge-danger {
      background: rgba(231, 76, 60, 0.15);
      color: var(--error);
    }

    .badge-info {
      background: rgba(107, 140, 192, 0.15);
      color: var(--primary-light);
    }

    /* Modal Styles */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }

    .modal {
      background: var(--card);
      border-radius: var(--radius);
      width: 90%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-lg);
    }

    .modal-header {
      padding: 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
    }

    .modal-body {
      padding: 20px;
    }

    .modal-footer {
      padding: 20px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .close-modal {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: white;
    }

    /* Form Styles */
    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text);
    }

    .form-control {
      width: 100%;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 14px;
      transition: var(--transition);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
    }

    /* Quick Access Buttons Styles */
    .quick-access-buttons {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-left: auto;
    }

    .logo-container {
      display: flex;
      align-items: center;
      gap: 16px;
      flex: 1;
      width: 100%;
    }

    .name-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 0;
    }

    .quick-mini-btn {
      background: var(--card);
      border: 2px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px 15px;
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow);
      width: 160px;
      text-align: left;
      border-left-width: 4px;
    }

    .quick-mini-btn:hover {
      transform: translateX(-5px);
      box-shadow: var(--shadow-lg);
    }

    .quick-mini-btn.inventory {
      border-left-color: var(--primary);
    }

    .quick-mini-btn.inventory:hover {
      background: linear-gradient(135deg, #f0f7ff, #ffffff);
      border-color: var(--primary-light);
    }

    .quick-mini-btn.bookings {
      border-left-color: var(--secondary);
    }

    .quick-mini-btn.bookings:hover {
      background: linear-gradient(135deg, #f0fff7, #ffffff);
      border-color: #7fd4c1;
    }

    .quick-mini-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      color: white;
      flex-shrink: 0;
    }

    .quick-mini-btn.inventory .quick-mini-icon {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .quick-mini-btn.bookings .quick-mini-icon {
      background: linear-gradient(135deg, var(--secondary), #4aa08d);
    }

    /* Oval Stat Cards with Left Accent Color */
    .oval-stats-container {
      margin: 20px 24px 30px 24px;
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
    }


    .oval-stat-card {
      flex: 1;
      min-width: 250px;
      max-width: 280px;
      background: var(--card);
      backdrop-filter: blur(10px);
      border-radius: 50px;
      /* Oval shape */
      padding: 20px 25px;
      display: flex;
      align-items: center;
      gap: 15px;
      transition: var(--transition);
      box-shadow: var(--shadow-lg);
      border: 2px solid var(--border);
      position: relative;
      overflow: hidden;
      cursor: pointer;
    }

    .oval-stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
    }

    /* Left accent color section - takes ~30% of the card */
    .oval-stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5%;
      /* Color occupies 30% from left */
      height: 100%;
      z-index: 0;
      border-radius: 50px 0 0 50px;
      /* Match oval shape on left side */
    }

    /* Different accent colors for each card */
    .oval-stat-card:nth-child(1)::before {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .oval-stat-card:nth-child(2)::before {
      background: linear-gradient(135deg, var(--success), #229954);
    }

    .oval-stat-card:nth-child(3)::before {
      background: linear-gradient(135deg, var(--info), #2980b9);
    }

    .oval-stat-card:nth-child(4)::before {
      background: linear-gradient(135deg, var(--warning), #d97706);
    }

    .oval-stat-icon {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      position: relative;
      z-index: 1;
    }

    /* Icon colors matching accent colors */
    .oval-stat-card:nth-child(1) .oval-stat-icon {
      color: var(--primary);
      border: 2px solid var(--primary-light);
    }

    .oval-stat-card:nth-child(2) .oval-stat-icon {
      color: var(--success);
      border: 2px solid rgba(39, 174, 96, 0.3);
    }

    .oval-stat-card:nth-child(3) .oval-stat-icon {
      color: var(--info);
      border: 2px solid rgba(52, 152, 219, 0.3);
    }

    .oval-stat-card:nth-child(4) .oval-stat-icon {
      color: var(--warning);
      border: 2px solid rgba(245, 158, 11, 0.3);
    }

    .oval-stat-content {
      flex: 1;
      min-width: 0;
      position: relative;
      z-index: 1;
      padding-left: 10px;
    }

    .oval-stat-value {
      font-size: 22px;
      font-weight: 800;
      color: var(--text);
      line-height: 1.2;
      margin-bottom: 4px;
      text-shadow: none;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .oval-stat-label {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-light);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* For currency values */
    .oval-stat-card:first-child .oval-stat-value {
      font-size: 21px;
      letter-spacing: 0.5px;
    }

    /* Ensure text is visible over colored background */
    .oval-stat-content {
      background: var(--card);
      padding: 8px 12px;
      border-radius: 8px;
      margin-left: -15px;
      /* Overlap slightly with colored section */
    }

    /* Responsive adjustments */
    @media (max-width: 1024px) {
      .oval-stat-card {
        min-width: calc(50% - 10px);
        max-width: calc(50% - 10px);
      }
    }

    @media (max-width: 768px) {
      .oval-stats-container {
        gap: 15px;
      }

      .oval-stat-card {
        min-width: calc(50% - 10px);
        max-width: calc(50% - 10px);
        padding: 18px 20px;
      }

      .oval-stat-value {
        font-size: 20px;
      }

      .oval-stat-label {
        font-size: 12px;
      }

      .oval-stat-icon {
        width: 45px;
        height: 45px;
        font-size: 18px;
      }
    }

    @media (max-width: 480px) {
      .oval-stat-card {
        min-width: 100%;
        max-width: 100%;
      }
    }


    .quick-mini-text {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .quick-mini-title {
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
      line-height: 1.2;
    }

    .quick-mini-count {
      font-size: 18px;
      font-weight: 800;
      line-height: 1.2;
      margin-top: 2px;
    }

    .quick-mini-btn.inventory .quick-mini-count {
      color: var(--primary);
    }

    .quick-mini-btn.bookings .quick-mini-count {
      color: var(--secondary);
    }

    /* Responsive adjustments for mobile */
    @media (max-width: 768px) {
      .quick-access-buttons {
        position: fixed;
        bottom: 20px;
        right: 20px;
        flex-direction: column-reverse;
        z-index: 1000;
      }

      .quick-mini-btn {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        padding: 0;
        justify-content: center;
        align-items: center;
        position: relative;
      }

      .quick-mini-text {
        display: none;
      }

      .quick-mini-icon {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        font-size: 20px;
      }

      .quick-mini-btn:hover .quick-mini-text {
        display: block;
        position: absolute;
        left: -160px;
        top: 50%;
        transform: translateY(-50%);
        background: var(--card);
        padding: 10px;
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow);
        width: 140px;
        white-space: nowrap;
        z-index: 1001;
      }
    }

    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .card-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
      }

      .logo-row {
        flex-direction: column;
        text-align: center;
        gap: 16px;
      }

      .logo-container {
        flex-direction: column;
        text-align: center;
      }

      /* Hide quick access buttons in header on mobile */
      .logo-container .quick-access-buttons {
        display: none;
      }
    }

    /* Tabs */
    .tabs {
      display: flex;
      border-bottom: 2px solid var(--border);
      margin-bottom: 20px;
    }

    .tab {
      padding: 12px 24px;
      cursor: pointer;
      background: none;
      border: none;
      border-bottom: 3px solid transparent;
      font-weight: 500;
      color: var(--text-light);
      transition: var(--transition);
    }

    .tab:hover {
      color: var(--primary);
    }

    .tab.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: var(--text-light);
    }

    .empty-state i {
      font-size: 48px;
      margin-bottom: 16px;
      opacity: 0.3;
    }

    /* Top Bar - Enhanced */
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
      width: 44px;
      height: 44px;
    }

    .floating-menu-btn:hover {
      background: var(--card-alt);
      transform: translateY(-1px);
      box-shadow: var(--shadow-lg);
      border-color: var(--primary-light);
    }

    .floating-menu-btn i {
      font-size: 18px;
    }

    .page-title {
      font-size: 24px;
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .page-title i {
      color: var(--primary);
      font-size: 20px;
      width: 24px;
      text-align: center;
    }

    /* Container */
    .container {
      padding: 24px;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
      flex: 1;
    }

    /* Main layout adjustment */
    .main {
      margin-left: 0;
      min-height: 100vh;
      background: var(--bg);
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .top-row {
        padding: 16px 20px;
      }

      .page-title {
        font-size: 20px;
      }

      .floating-menu-btn {
        width: 40px;
        height: 40px;
        padding: 10px;
      }
    }

    @media (max-width: 480px) {
      .top-row {
        padding: 12px 16px;
      }

      .page-title {
        font-size: 18px;
      }

      .floating-menu-btn {
        width: 36px;
        height: 36px;
        padding: 8px;
      }

      .page-title i {
        font-size: 16px;
      }
    }

    .text-center {
      text-align: center;
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
      .main {
        margin-left: 0;
      }

      .quick-access-grid {
        grid-template-columns: 1fr;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 768px) {
      .top-row {
        padding: 16px;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .quick-access-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 480px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .card-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
      }

      .logo-row {
        flex-direction: column;
        text-align: center;
        gap: 16px;
      }

      .logo-container {
        flex-direction: column;
        text-align: center;
      }


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

          <button class="menu-btn active" type="button">
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

    <!-- Main Content -->
    <main class="main" id="main">
      <!-- Top Bar -->
      <section class="top-row">
        <button class="floating-menu-btn" id="menuToggle" aria-controls="sidebar" aria-expanded="false"
          aria-label="Open menu" type="button">
          <i class="fas fa-bars"></i>
        </button>
        <div class="page-title">
          <i class="fas fa-boxes"></i>
          Asset Management
        </div>

        <!-- Add the logo and mahal info here -->
        <div class="logo-row" style="display: none;"> <!-- Hide the old logo row since we're moving it -->
          <div class="logo-container">
            <div class="name-container">
              <div class="name-ar"><?php echo htmlspecialchars($mahal['name']); ?></div>
              <div class="name-subtitle">
                <i class="fas fa-map-marker-alt"></i>
                <?php echo htmlspecialchars($mahal['address'] ?? 'Registered Mosque'); ?>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Statistics Cards -->
      <div class="oval-stats-container">
        <div class="oval-stat-card">
          <div class="oval-stat-icon">
            <i class="fas fa-rupee-sign"></i>
          </div>
          <div class="oval-stat-content">
            <div class="oval-stat-value">₹<?php echo number_format($total_value, 2); ?></div>
            <div class="oval-stat-label">Total Asset Value</div>
          </div>
        </div>

        <div class="oval-stat-card">
          <div class="oval-stat-icon">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="oval-stat-content">
            <div class="oval-stat-value"><?php echo number_format($total_assets); ?></div>
            <div class="oval-stat-label">Total Assets</div>
          </div>
        </div>

        <div class="oval-stat-card">
          <div class="oval-stat-icon">
            <i class="fas fa-calendar-alt"></i>
          </div>
          <div class="oval-stat-content">
            <div class="oval-stat-value"><?php echo $active_bookings; ?></div>
            <div class="oval-stat-label">Active Bookings</div>
          </div>
        </div>

        <div class="oval-stat-card">
          <div class="oval-stat-icon">
            <i class="fas fa-receipt"></i>
          </div>
          <div class="oval-stat-content">
            <div class="oval-stat-value">₹<?php echo number_format($total_tax, 2); ?></div>
            <div class="oval-stat-label">Total Taxable Amount</div>
          </div>
        </div>
      </div>

      <!-- Continue with the rest of your content sections... -->
      </section>

      <!-- Dashboard Section -->
      <div id="dashboard-section" class="content-section active">
        <!-- Quick Actions -->
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
          </div>
          <div class="card-body">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
              <button class="btn btn-primary" onclick="openModal('addAssetModal')">
                <i class="fas fa-plus"></i> Add New Asset
              </button>
              <button class="btn btn-success" onclick="openModal('scheduleMaintenanceModal')">
                <i class="fas fa-calendar-plus"></i> Schedule Maintenance
              </button>
              <button class="btn btn-info" onclick="openModal('bookAssetModal')">
                <i class="fas fa-book"></i> Book Asset
              </button>
              <button class="btn btn-warning" onclick="openModal('addCategoryModal')">
                <i class="fas fa-tag"></i> Add Category
              </button>
            </div>
          </div>
        </div>

        <!-- Recent Activity -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
          <!-- Available Categories -->
          <div class="card">
            <div class="card-header">
              <h3><i class="fas fa-folder-open"></i> Available Categories</h3>
              <span class="badge badge-info"><?php echo count($categories_with_stats); ?> Categories</span>
            </div>
            <div class="card-body">
              <div class="categories-grid"
                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px;">
                <?php if (count($categories_with_stats) > 0): ?>
                  <?php foreach ($categories_with_stats as $category): ?>
                    <div class="category-card"
                      onclick="viewCategoryAssets(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')"
                      style="cursor: pointer; background: linear-gradient(135deg, #f8fafc, #ffffff); border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; transition: all 0.3s ease; position: relative; overflow: hidden;">

                      <!-- Category name and count -->
                      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #4a6fa5; line-height: 1.2;">
                          <?php echo htmlspecialchars($category['category_name']); ?>
                        </h4>
                        <span
                          style="background: #4a6fa5; color: white; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 10px; min-width: 24px; text-align: center;">
                          <?php echo $category['asset_count']; ?>
                        </span>
                      </div>

                      <!-- Description (truncated) -->
                      <?php if (!empty($category['description'])): ?>
                        <p
                          style="margin: 0; font-size: 11px; color: #7f8c8d; line-height: 1.3; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                          <?php echo htmlspecialchars($category['description']); ?>
                        </p>
                      <?php endif; ?>

                      <!-- Hover effect -->
                      <div
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(74, 111, 165, 0.05), rgba(107, 140, 192, 0.05)); opacity: 0; transition: opacity 0.3s ease;">
                      </div>
                    </div>

                    <style>
                      .category-card:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                        border-color: #4a6fa5;
                      }

                      .category-card:hover>div:last-child {
                        opacity: 1;
                      }
                    </style>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div style="grid-column: 1 / -1; text-align: center; padding: 30px; color: #7f8c8d;">
                    <i class="fas fa-folder-open" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px;"></i>
                    <p>No categories found. Add categories to organize your assets.</p>
                    <button class="btn btn-sm btn-primary" onclick="openModal('addCategoryModal')">
                      <i class="fas fa-plus"></i> Add First Category
                    </button>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Summary at bottom -->
              <?php if (count($categories_with_stats) > 0): ?>
                <div
                  style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 12px; color: #7f8c8d;">
                  <div>
                    <i class="fas fa-info-circle"></i>
                    <?php
                    $total_categories = count($categories_with_stats);
                    $total_items = array_sum(array_column($categories_with_stats, 'asset_count'));
                    echo "{$total_categories} categories • {$total_items} total assets";
                    ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Upcoming Maintenance -->
          <div class="card">
            <div class="card-header">
              <h3><i class="fas fa-tools"></i> Upcoming Maintenance</h3>
              <a href="maintenance_view_all.php" class="btn btn-sm btn-primary">
                <i class="fas fa-eye"></i> View All
              </a>
            </div>
            <div class="card-body">
              <div class="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>Asset</th>
                      <th>Due Date</th>
                      <th>Priority</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    try {
                      $stmt = $conn->prepare("SELECT a.name, m.due_date, m.priority, m.status FROM asset_maintenance m JOIN assets a ON m.asset_id = a.id WHERE m.mahal_id = ? AND m.status IN ('scheduled', 'in_progress') ORDER BY m.due_date ASC LIMIT 5");
                      $stmt->bind_param("i", $mahal_id);
                      $stmt->execute();
                      $result = $stmt->get_result();
                      $upcoming_maintenance = $result->fetch_all(MYSQLI_ASSOC);
                      $stmt->close();

                      if (count($upcoming_maintenance) > 0) {
                        foreach ($upcoming_maintenance as $maintenance) {
                          $priority_badge = '';
                          switch ($maintenance['priority']) {
                            case 'low':
                              $priority_badge = 'badge-info';
                              break;
                            case 'normal':
                              $priority_badge = 'badge-info';
                              break;
                            case 'high':
                              $priority_badge = 'badge-warning';
                              break;
                            case 'critical':
                              $priority_badge = 'badge-danger';
                              break;
                          }

                          $status_badge = $maintenance['status'] == 'scheduled' ? 'badge-info' : 'badge-warning';

                          echo "<tr>
                            <td>{$maintenance['name']}</td>
                            <td>" . date('M j, Y', strtotime($maintenance['due_date'])) . "</td>
                            <td><span class='badge {$priority_badge}'>" . ucfirst($maintenance['priority']) . "</span></td>
                            <td><span class='badge {$status_badge}'>" . ucfirst($maintenance['status']) . "</span></td>
                          </tr>";
                        }
                      } else {
                        echo "<tr><td colspan='4' class='text-center'>No maintenance scheduled</td></tr>";
                      }
                    } catch (Exception $e) {
                      echo "<tr><td colspan='4' class='text-center'>No maintenance scheduled</td></tr>";
                    }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Recent Bookings -->
          <div class="card">
            <div class="card-header">
              <h3><i class="fas fa-calendar-check"></i> Recent Bookings</h3>
              <a href="bookings_view_all.php" class="btn btn-sm btn-primary">
                <i class="fas fa-eye"></i> View All
              </a>
            </div>
            <div class="card-body">
              <div class="table-container">
                <table>
                  <thead>
                    <tr>
                      <th>Asset</th>
                      <th>Date Range</th>
                      <th>Booked By</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    try {
                      $stmt = $conn->prepare("SELECT a.name, b.start_date, b.end_date, b.booked_by, b.status FROM asset_bookings b JOIN assets a ON b.asset_id = a.id WHERE b.mahal_id = ? ORDER BY b.created_at DESC LIMIT 5");
                      $stmt->bind_param("i", $mahal_id);
                      $stmt->execute();
                      $result = $stmt->get_result();
                      $recent_bookings = $result->fetch_all(MYSQLI_ASSOC);
                      $stmt->close();

                      if (count($recent_bookings) > 0) {
                        foreach ($recent_bookings as $booking) {
                          $status_badge = '';
                          switch ($booking['status']) {
                            case 'pending':
                              $status_badge = 'badge-warning';
                              break;
                            case 'approved':
                              $status_badge = 'badge-success';
                              break;
                            case 'rejected':
                              $status_badge = 'badge-danger';
                              break;
                            case 'cancelled':
                              $status_badge = 'badge-secondary';
                              break;
                          }

                          echo "<tr>
                            <td>{$booking['name']}</td>
                            <td>" . date('M j', strtotime($booking['start_date'])) . " - " . date('M j', strtotime($booking['end_date'])) . "</td>
                            <td>{$booking['booked_by']}</td>
                            <td><span class='badge {$status_badge}'>" . ucfirst($booking['status']) . "</span></td>
                          </tr>";
                        }
                      } else {
                        echo "<tr><td colspan='4' class='text-center'>No bookings found</td></tr>";
                      }
                    } catch (Exception $e) {
                      echo "<tr><td colspan='4' class='text-center'>No bookings found</td></tr>";
                    }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Inventory Section -->
      <div id="inventory-section" class="content-section">
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-boxes"></i> Asset Inventory</h3>
            <div style="display: flex; gap: 15px; align-items: center;">
              <div style="flex: 1; min-width: 200px;">
                <input type="text" id="searchAssets" class="form-control" placeholder="Search assets..."
                  onkeyup="searchTable('assetsTable', this.value)">
              </div>
              <div style="min-width: 150px;">
                <select id="filterCategory" class="form-control" onchange="filterAssets()">
                  <option value="">All Categories</option>
                  <?php foreach ($categories_list as $category): ?>
                    <option value="<?php echo $category['id']; ?>">
                      <?php echo htmlspecialchars($category['category_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="min-width: 150px;">
                <select id="filterRental" class="form-control" onchange="filterAssets()">
                  <option value="">All Types</option>
                  <option value="rental">Rental</option>
                  <option value="non_rental">Non-Rental</option>
                </select>
              </div>
              <button class="btn btn-primary" onclick="openModal('addAssetModal')">
                <i class="fas fa-plus"></i> Add Asset
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table id="assetsTable">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Purchase Cost</th>
                    <th>Current Value</th>
                    <th>Taxable Amount</th>
                    <th>Rental Status</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $stmt = $conn->prepare("SELECT a.*, ac.category_name FROM assets a JOIN asset_categories ac ON a.category_id = ac.id WHERE a.mahal_id = ? ORDER BY a.created_at DESC");
                  $stmt->bind_param("i", $mahal_id);
                  $stmt->execute();
                  $result = $stmt->get_result();
                  $all_assets = $result->fetch_all(MYSQLI_ASSOC);
                  $stmt->close();

                  if (count($all_assets) > 0) {
                    foreach ($all_assets as $asset) {
                      $condition_badge = '';
                      switch ($asset['condition_status']) {
                        case 'excellent':
                          $condition_badge = 'badge-success';
                          break;
                        case 'good':
                          $condition_badge = 'badge-success';
                          break;
                        case 'fair':
                          $condition_badge = 'badge-warning';
                          break;
                        case 'needs_repair':
                          $condition_badge = 'badge-danger';
                          break;
                        case 'out_of_service':
                          $condition_badge = 'badge-secondary';
                          break;
                      }

                      $status_badge = '';
                      switch ($asset['status']) {
                        case 'active':
                          $status_badge = 'badge-success';
                          break;
                        case 'inactive':
                          $status_badge = 'badge-warning';
                          break;
                        case 'disposed':
                          $status_badge = 'badge-secondary';
                          break;
                        case 'lost':
                          $status_badge = 'badge-danger';
                          break;
                      }

                      $rental_badge = $asset['rental_status'] == 'rental' ? 'badge-info' : 'badge-secondary';
                      $rental_text = $asset['rental_status'] == 'rental' ? 'Rental' : 'Non-Rental';

                      echo "<tr data-category='{$asset['category_id']}' data-rental='{$asset['rental_status']}' data-status='{$asset['status']}'>
                        <td><strong>{$asset['asset_code']}</strong></td>
                        <td>{$asset['name']}</td>
                        <td>{$asset['category_name']}</td>
                        <td>₹" . number_format($asset['purchase_cost'], 2) . "</td>
                        <td>₹" . number_format($asset['current_value'], 2) . "</td>
                        <td>₹" . number_format($asset['taxable_amount'], 2) . "</td>
                        <td><span class='badge {$rental_badge}'>$rental_text</span></td>
                        <td><span class='badge {$condition_badge}'>" . ucfirst(str_replace('_', ' ', $asset['condition_status'])) . "</span></td>
                        <td><span class='badge {$status_badge}'>" . ucfirst($asset['status']) . "</span></td>
                        <td>
                          <a href='asset_details.php?id={$asset['id']}' class='btn btn-sm btn-info' title='View Details'>
                            <i class='fas fa-eye'></i>
                          </a>
                          <button class='btn btn-sm btn-warning' onclick='editAsset({$asset['id']})' title='Edit'>
                            <i class='fas fa-edit'></i>
                          </button>
                          <button class='btn btn-sm btn-danger' onclick='deleteAsset({$asset['id']}, \"{$asset['name']}\")' title='Delete'>
                            <i class='fas fa-trash'></i>
                          </button>
                        </td>
                      </tr>";
                    }
                  } else {
                    echo "<tr><td colspan='10' class='text-center'>No assets found. Click 'Add Asset' to get started!</td></tr>";
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Maintenance Section -->
      <div id="maintenance-section" class="content-section">
        <div class="tabs">
          <button class="tab active" onclick="showMaintenanceTab('upcoming')">Upcoming</button>
          <button class="tab" onclick="showMaintenanceTab('inprogress')">In Progress</button>
          <button class="tab" onclick="showMaintenanceTab('completed')">Completed</button>
          <button class="tab" onclick="showMaintenanceTab('all')">All</button>
        </div>
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-tools"></i> Maintenance Schedule</h3>
            <button class="btn btn-success" onclick="openModal('scheduleMaintenanceModal')">
              <i class="fas fa-calendar-plus"></i> Schedule Maintenance
            </button>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table id="maintenanceTable">
                <thead>
                  <tr>
                    <th>Asset</th>
                    <th>Type</th>
                    <th>Scheduled</th>
                    <th>Due Date</th>
                    <th>Priority</th>
                    <th>Assigned To</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $stmt = $conn->prepare("SELECT m.*, a.name as asset_name, s.name as staff_name FROM asset_maintenance m JOIN assets a ON m.asset_id = a.id LEFT JOIN staff s ON m.assigned_to = s.id WHERE m.mahal_id = ? ORDER BY m.due_date ASC");
                  $stmt->bind_param("i", $mahal_id);
                  $stmt->execute();
                  $result = $stmt->get_result();
                  $maintenance_list = $result->fetch_all(MYSQLI_ASSOC);
                  $stmt->close();

                  if (count($maintenance_list) > 0) {
                    foreach ($maintenance_list as $maintenance) {
                      $priority_badge = '';
                      switch ($maintenance['priority']) {
                        case 'low':
                          $priority_badge = 'badge-info';
                          break;
                        case 'normal':
                          $priority_badge = 'badge-info';
                          break;
                        case 'high':
                          $priority_badge = 'badge-warning';
                          break;
                        case 'critical':
                          $priority_badge = 'badge-danger';
                          break;
                      }

                      $status_badge = '';
                      switch ($maintenance['status']) {
                        case 'scheduled':
                          $status_badge = 'badge-info';
                          break;
                        case 'in_progress':
                          $status_badge = 'badge-warning';
                          break;
                        case 'completed':
                          $status_badge = 'badge-success';
                          break;
                        case 'cancelled':
                          $status_badge = 'badge-secondary';
                          break;
                      }

                      $staff_name = $maintenance['staff_name'] ?: 'Not Assigned';
                      $cost = $maintenance['actual_cost'] > 0 ? $maintenance['actual_cost'] : $maintenance['estimated_cost'];

                      echo "<tr data-status='{$maintenance['status']}'>
                        <td>{$maintenance['asset_name']}</td>
                        <td>" . ucfirst(str_replace('_', ' ', $maintenance['maintenance_type'])) . "</td>
                        <td>" . date('M j, Y', strtotime($maintenance['scheduled_date'])) . "</td>
                        <td>" . date('M j, Y', strtotime($maintenance['due_date'])) . "</td>
                        <td><span class='badge {$priority_badge}'>" . ucfirst($maintenance['priority']) . "</span></td>
                        <td>{$staff_name}</td>
                        <td>₹" . number_format($cost, 2) . "</td>
                        <td><span class='badge {$status_badge}'>" . ucfirst(str_replace('_', ' ', $maintenance['status'])) . "</span></td>
                        <td>
                          <div style='display: flex; gap: 5px;'>
                            <button class='btn btn-sm btn-success' onclick='updateMaintenanceStatus({$maintenance['id']}, \"completed\")' title='Mark Complete'>
                              <i class='fas fa-check'></i>
                            </button>
                            <button class='btn btn-sm btn-warning' onclick='updateMaintenanceStatus({$maintenance['id']}, \"in_progress\")' title='Start Progress'>
                              <i class='fas fa-play'></i>
                            </button>
                            <button class='btn btn-sm btn-danger' onclick='updateMaintenanceStatus({$maintenance['id']}, \"cancelled\")' title='Cancel'>
                              <i class='fas fa-times'></i>
                            </button>
                          </div>
                        </td>
                      </tr>";
                    }
                  } else {
                    echo "<tr><td colspan='9' class='text-center'>No maintenance scheduled</td></tr>";
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Bookings Section -->
      <div id="bookings-section" class="content-section">
        <div class="tabs">
          <button class="tab" onclick="showBookingsTab('pending')">Pending</button>
          <button class="tab" onclick="showBookingsTab('approved')">Approved</button>
          <button class="tab" onclick="showBookingsTab('rejected')">Rejected</button>
          <button class="tab active" onclick="showBookingsTab('all')">All</button>
        </div>
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-calendar-check"></i> Asset Bookings</h3>
            <button class="btn btn-info" onclick="openModal('bookAssetModal')">
              <i class="fas fa-book"></i> New Booking
            </button>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table id="bookingsTable">
                <thead>
                  <tr>
                    <th>Asset</th>
                    <th>Date Range</th>
                    <th>Booked By</th>
                    <th>Purpose</th>
                    <th>Amount (₹)</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $stmt = $conn->prepare("SELECT b.*, a.name as asset_name FROM asset_bookings b LEFT JOIN assets a ON b.asset_id = a.id WHERE b.mahal_id = ? ORDER BY b.start_date DESC");
                  $stmt->bind_param("i", $mahal_id);
                  $stmt->execute();
                  $result = $stmt->get_result();
                  $bookings_list = $result->fetch_all(MYSQLI_ASSOC);
                  $stmt->close();

                  if (count($bookings_list) > 0) {
                    foreach ($bookings_list as $booking) {
                      $status_badge = '';
                      switch ($booking['status']) {
                        case 'pending':
                          $status_badge = 'badge-warning';
                          break;
                        case 'approved':
                          $status_badge = 'badge-success';
                          break;
                        case 'rejected':
                          $status_badge = 'badge-danger';
                          break;
                        case 'cancelled':
                          $status_badge = 'badge-secondary';
                          break;
                      }

                      $actions = '';
                      if ($booking['status'] == 'pending') {
                        $actions = "
                          <div style='display: flex; gap: 5px;'>
                            <button class='btn btn-sm btn-success' onclick='updateBookingStatus({$booking['id']}, \"approved\")' title='Approve'>
                              <i class='fas fa-check'></i>
                            </button>
                            <button class='btn btn-sm btn-danger' onclick='updateBookingStatus({$booking['id']}, \"rejected\")' title='Reject'>
                              <i class='fas fa-times'></i>
                            </button>
                          </div>
                        ";
                      } else {
                        $actions = "<span style='color: var(--text-light); font-size: 12px;'>Action completed</span>";
                      }

                      // Handle deleted assets
                      $asset_name = $booking['asset_name'] ?? '<span class="text-muted">Deleted Asset</span>';

                      echo "<tr data-status='{$booking['status']}'>
                        <td>{$asset_name}</td>
                        <td>" . date('M j', strtotime($booking['start_date'])) . " - " . date('M j', strtotime($booking['end_date'])) . "</td>
                        <td>{$booking['booked_by']}</td>
                        <td>{$booking['purpose']}</td>
                        <td>₹" . number_format($booking['booking_amount'], 2) . "</td>
                        <td><span class='badge {$status_badge}'>" . ucfirst($booking['status']) . "</span></td>
                        <td>{$actions}</td>
                      </tr>";
                    }
                  } else {
                    echo "<tr><td colspan='7' class='text-center'>No bookings found</td></tr>";
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Categories Section -->
      <div id="categories-section" class="content-section">
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-tags"></i> Asset Categories</h3>
            <button class="btn btn-warning" onclick="openModal('addCategoryModal')">
              <i class="fas fa-plus"></i> Add Category
            </button>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table>
                <thead>
                  <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th>Asset Count</th>
                    <th>Total Value</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $stmt = $conn->prepare("SELECT c.*, COUNT(a.id) as asset_count, COALESCE(SUM(a.current_value), 0) as total_value FROM asset_categories c LEFT JOIN assets a ON c.id = a.category_id AND a.status = 'active' WHERE c.mahal_id = ? GROUP BY c.id ORDER BY c.category_name");
                  $stmt->bind_param("i", $mahal_id);
                  $stmt->execute();
                  $result = $stmt->get_result();
                  $categories_details = $result->fetch_all(MYSQLI_ASSOC);
                  $stmt->close();

                  if (count($categories_details) > 0) {
                    foreach ($categories_details as $category) {
                      echo "<tr>
                        <td><strong>{$category['category_name']}</strong></td>
                        <td>{$category['description']}</td>
                        <td>{$category['asset_count']}</td>
                        <td>₹" . number_format($category['total_value'], 2) . "</td>
                        <td>
                          <button class='btn btn-sm btn-warning' onclick='editCategory({$category['id']})' title='Edit'>
                            <i class='fas fa-edit'></i>
                          </button>
                          <button class='btn btn-sm btn-danger' onclick='deleteCategory({$category['id']}, \"{$category['category_name']}\")' title='Delete'>
                            <i class='fas fa-trash'></i>
                          </button>
                        </td>
                      </tr>";
                    }
                  } else {
                    echo "<tr><td colspan='5' class='text-center'>No categories found</td></tr>";
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Reports Section -->
      <div id="reports-section" class="content-section">
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-chart-bar"></i> Asset Reports</h3>
          </div>
          <div class="card-body">
            <div
              style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
              <!-- Asset Value by Category -->
              <div class="card">
                <div class="card-header">
                  <h4>Asset Value by Category</h4>
                </div>
                <div class="card-body">
                  <canvas id="categoryChart" width="400" height="300"></canvas>
                </div>
              </div>

              <!-- Maintenance Status -->
              <div class="card">
                <div class="card-header">
                  <h4>Maintenance Status</h4>
                </div>
                <div class="card-body">
                  <canvas id="maintenanceChart" width="400" height="300"></canvas>
                </div>
              </div>

              <!-- Booking Statistics -->
              <div class="card">
                <div class="card-header">
                  <h4>Booking Statistics</h4>
                </div>
                <div class="card-body">
                  <canvas id="bookingChart" width="400" height="300"></canvas>
                </div>
              </div>

              <!-- Asset Condition -->
              <div class="card">
                <div class="card-header">
                  <h4>Asset Condition Overview</h4>
                </div>
                <div class="card-body">
                  <canvas id="conditionChart" width="400" height="300"></canvas>
                </div>
              </div>
            </div>

            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
              <button class="btn btn-primary" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf"></i> Export PDF Report
              </button>
              <button class="btn btn-success" onclick="exportReport('excel')">
                <i class="fas fa-file-excel"></i> Export Excel Report
              </button>
              <button class="btn btn-info" onclick="printReport()">
                <i class="fas fa-print"></i> Print Report
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Add Asset Modal -->
  <div class="modal-overlay" id="addAssetModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-plus"></i> Add New Asset</h3>
        <button class="close-modal" onclick="closeModal('addAssetModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addAssetForm" method="POST">
          <input type="hidden" name="action" value="add_asset">

          <div class="form-group">
            <label for="asset_name">Asset Name *</label>
            <input type="text" id="asset_name" name="asset_name" class="form-control" required
              placeholder="e.g., Sound System, Projector, AC Unit">
          </div>

          <div class="form-group">
            <label for="quantity">Quantity (Number of Items) *</label>
            <input type="number" id="quantity" name="quantity" class="form-control" required min="1" value="1"
              placeholder="Enter quantity">
            <small style="color: var(--text-light); font-size: 0.85em;">Entering > 1 will create multiple individual
              asset records.</small>
          </div>

          <div class="form-group">
            <label for="category_id">Category *</label>
            <select id="category_id" name="category_id" class="form-control" required>
              <option value="">Select Category</option>
              <?php foreach ($categories_list as $category): ?>
                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Asset Type *</label>
            <div style="display: flex; gap: 20px; margin-top: 8px;">
              <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="radio" name="asset_type" value="existing" checked onchange="togglePurchaseFields()">
                <span>Already Existing</span>
              </label>
              <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="radio" name="asset_type" value="new_purchase" onchange="togglePurchaseFields()">
                <span>New Purchase</span>
              </label>
            </div>
          </div>

          <div id="purchaseFields" style="display: none;">
            <div class="form-group">
              <label for="acquisition_date">Purchase Date *</label>
              <input type="date" id="acquisition_date" name="acquisition_date" class="form-control">
            </div>

            <div class="form-group">
              <label for="vendor_donor">Vendor/Donor</label>
              <input type="text" id="vendor_donor" name="vendor_donor" class="form-control" placeholder="Optional">
            </div>

            <div class="form-group">
              <label for="purchase_cost">Purchase Cost (Per Item) (₹) *</label>
              <input type="number" id="purchase_cost" name="purchase_cost" class="form-control" step="0.01" min="0"
                placeholder="0.00">
            </div>

          </div>

          <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <label for="taxable_amount" style="margin-bottom: 0;">Taxable Amount (₹)</label>
              <div style="display: flex; align-items: center; gap: 5px;">
                <input type="checkbox" id="is_taxable" onchange="toggleTaxableAmount('add')" style="width: auto;">
                <label for="is_taxable" style="font-size: 12px; margin-bottom: 0; cursor: pointer;">Taxable?</label>
              </div>
            </div>
            <input type="number" id="taxable_amount" name="taxable_amount" class="form-control" step="0.01" min="0"
              value="0.00" readonly>
            <small class="text-muted">Check box to enter manually</small>
          </div>

          <div class="form-group">
            <label for="rental_status">Rental Status *</label>
            <select id="rental_status" name="rental_status" class="form-control" required>
              <option value="">Select Rental Status</option>
              <option value="rental">Rental</option>
              <option value="non_rental">Non-Rental</option>
            </select>
            <small class="text-muted">Select whether this asset is available for rental</small>
          </div>

          <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="3"
              placeholder="Additional details about the asset"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addAssetModal')">Cancel</button>
        <button type="submit" form="addAssetForm" class="btn btn-primary">
          <i class="fas fa-save"></i> Save Asset
        </button>
      </div>
    </div>
  </div>

  <!-- Edit Asset Modal -->
  <div class="modal-overlay" id="editAssetModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-edit"></i> Edit Asset</h3>
        <button class="close-modal" onclick="closeModal('editAssetModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="editAssetForm" method="POST" action="">
          <input type="hidden" name="action" value="update_asset">
          <input type="hidden" name="asset_id" id="edit_asset_id">

          <div class="form-group">
            <label for="edit_asset_name">Asset Name *</label>
            <input type="text" id="edit_asset_name" name="asset_name" class="form-control" required>
          </div>

          <div class="form-group">
            <label for="edit_category_id">Category *</label>
            <select id="edit_category_id" name="category_id" class="form-control" required>
              <option value="">Select Category</option>
              <?php foreach ($categories_list as $category): ?>
                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="edit_acquisition_date">Acquisition/Purchase Date *</label>
            <input type="date" id="edit_acquisition_date" name="acquisition_date" class="form-control" required>
          </div>

          <div class="form-group">
            <label for="edit_vendor_donor">Vendor/Donor</label>
            <input type="text" id="edit_vendor_donor" name="vendor_donor" class="form-control" placeholder="Optional">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <label for="edit_purchase_cost">Purchase Cost (₹) *</label>
              <input type="number" id="edit_purchase_cost" name="purchase_cost" class="form-control" step="0.01"
                required min="0">
            </div>

            <div class="form-group">
              <label for="edit_current_value">Current Value (₹) *</label>
              <input type="number" id="edit_current_value" name="current_value" class="form-control" step="0.01"
                required min="0">
              <small class="text-muted">Adjust manually if needed</small>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label for="edit_taxable_amount" style="margin-bottom: 0;">Taxable Amount (₹)</label>
                <div style="display: flex; align-items: center; gap: 5px;">
                  <input type="checkbox" id="edit_is_taxable" onchange="toggleTaxableAmount('edit')"
                    style="width: auto;">
                  <label for="edit_is_taxable"
                    style="font-size: 12px; margin-bottom: 0; cursor: pointer;">Taxable?</label>
                </div>
              </div>
              <input type="number" id="edit_taxable_amount" name="taxable_amount" class="form-control" step="0.01"
                min="0" readonly>
              <small class="text-muted">Check box to enter manually</small>
            </div>

            <div class="form-group">
              <label for="edit_rental_status">Rental Status *</label>
              <select id="edit_rental_status" name="rental_status" class="form-control" required>
                <option value="">Select Rental Status</option>
                <option value="rental">Rental</option>
                <option value="non_rental">Non-Rental</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <label for="edit_condition_status">Current Condition *</label>
              <select id="edit_condition_status" name="condition_status" class="form-control" required>
                <option value="">Select Condition</option>
                <option value="excellent">Excellent</option>
                <option value="good">Good</option>
                <option value="fair">Fair</option>
                <option value="needs_repair">Needs Repair</option>
                <option value="out_of_service">Out of Service</option>
              </select>
            </div>

            <div class="form-group">
              <label for="edit_asset_status">Asset Status *</label>
              <select id="edit_asset_status" name="asset_status" class="form-control" required>
                <option value="">Select Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="disposed">Disposed</option>
                <option value="lost">Lost</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <label for="edit_location">Location</label>
              <input type="text" id="edit_location" name="location" class="form-control"
                placeholder="e.g., Ground Floor, Room 101">
            </div>

            <div class="form-group">
              <label for="edit_assigned_to">Assigned Staff (Optional)</label>
              <select id="edit_assigned_to" name="assigned_to" class="form-control">
                <option value="">Select Staff (Optional)</option>
                <?php foreach ($staff_list as $staff_member): ?>
                  <option value="<?php echo $staff_member['id']; ?>">
                    <?php echo htmlspecialchars($staff_member['name'] . ' (' . $staff_member['staff_id'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="edit_maintenance_frequency">Maintenance Frequency</label>
            <select id="edit_maintenance_frequency" name="maintenance_frequency" class="form-control">
              <option value="">Select Schedule (Optional)</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="semi_annual">Semi-Annual</option>
              <option value="annual">Annual</option>
              <option value="as_needed">As Needed</option>
            </select>
          </div>

          <div class="form-group">
            <label for="edit_description">Description</label>
            <textarea id="edit_description" name="description" class="form-control" rows="3"
              placeholder="Additional details about the asset"></textarea>
          </div>

          <div class="form-group">
            <label for="edit_notes">Internal Notes</label>
            <textarea id="edit_notes" name="notes" class="form-control" rows="2"
              placeholder="Internal notes for administrators"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editAssetModal')">Cancel</button>
        <button type="submit" form="editAssetForm" class="btn btn-primary">
          <i class="fas fa-save"></i> Update Asset
        </button>
      </div>
    </div>
  </div>

  <!-- Schedule Maintenance Modal -->
  <div class="modal-overlay" id="scheduleMaintenanceModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-calendar-plus"></i> Schedule Maintenance</h3>
        <button class="close-modal" onclick="closeModal('scheduleMaintenanceModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="scheduleMaintenanceForm" method="POST">
          <input type="hidden" name="action" value="schedule_maintenance">
          <div class="form-group">
            <label for="asset_id">Select Asset *</label>
            <select id="asset_id" name="asset_id" class="form-control" required>
              <option value="">Select Asset</option>
              <?php foreach ($assets_list as $asset): ?>
                <option value="<?php echo $asset['id']; ?>">
                  <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['name'] . ' (' . $asset['category_name'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="maintenance_type">Maintenance Type *</label>
            <select id="maintenance_type" name="maintenance_type" class="form-control" required>
              <option value="">Select Type</option>
              <option value="routine_inspection">Routine Inspection</option>
              <option value="cleaning">Cleaning</option>
              <option value="repair">Repair</option>
              <option value="replacement">Replacement</option>
              <option value="safety_check">Safety Check</option>
              <option value="servicing">Servicing</option>
              <option value="calibration">Calibration</option>
            </select>
          </div>

          <div class="form-group">
            <label for="due_date">Due Date *</label>
            <input type="date" id="due_date" name="due_date" class="form-control" required>
          </div>

          <div class="form-group">
            <label for="priority">Priority *</label>
            <select id="priority" name="priority" class="form-control" required>
              <option value="">Select Priority</option>
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>

          <div class="form-group">
            <label for="assigned_to_maintenance">Assigned Staff</label>
            <select id="assigned_to_maintenance" name="assigned_to" class="form-control">
              <option value="">Select Staff (Optional)</option>
              <?php foreach ($staff_list as $staff_member): ?>
                <option value="<?php echo $staff_member['id']; ?>">
                  <?php echo htmlspecialchars($staff_member['name'] . ' (' . $staff_member['staff_id'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="estimated_cost">Estimated Cost (₹)</label>
            <input type="number" id="estimated_cost" name="estimated_cost" class="form-control" step="0.01" min="0">
          </div>

          <div class="form-group">
            <label for="description_maintenance">Description</label>
            <textarea id="description_maintenance" name="description" class="form-control" rows="3"
              placeholder="Describe the maintenance work needed"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleMaintenanceModal')">Cancel</button>
        <button type="submit" form="scheduleMaintenanceForm" class="btn btn-primary">
          <i class="fas fa-calendar-check"></i> Schedule Maintenance
        </button>
      </div>
    </div>
  </div>

  <!-- Book Asset Modal -->
  <div class="modal-overlay" id="bookAssetModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-book"></i> Book Asset</h3>
        <button class="close-modal" onclick="closeModal('bookAssetModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="bookAssetForm" method="POST">
          <input type="hidden" name="action" value="book_asset">

          <!-- FIXED: Changed from asset_name to asset_id -->
          <div class="form-group">
            <label for="booking_asset_id">Select Asset *</label>
            <select id="booking_asset_id" name="asset_id" class="form-control" required
              onchange="updateMaxQuantity(this)">
              <option value="" data-max="0">Select Asset</option>
              <?php foreach ($rental_assets_list as $asset): ?>
                <option value="<?php echo $asset['id']; ?>" data-max="<?php echo $asset['asset_count']; ?>">
                  <?php echo htmlspecialchars($asset['name'] . ' (Total: ' . $asset['asset_count'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="booking_quantity">Quantity *</label>
            <input type="number" id="booking_quantity" name="quantity" class="form-control" required min="1" value="1">
            <small id="available_qty_hint" class="text-muted">Select an asset to see availability</small>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
              <label for="start_date">Start Date *</label>
              <input type="date" id="start_date" name="start_date" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="end_date">End Date *</label>
              <input type="date" id="end_date" name="end_date" class="form-control" required>
            </div>
          </div>

          <div class="form-group">
            <label for="booking_amount">Booking Amount (₹)</label>
            <input type="number" id="booking_amount" name="booking_amount" class="form-control" step="0.01" min="0"
              placeholder="0.00">
          </div>

          <div class="form-group">
            <label for="booked_by">Booked By *</label>
            <input type="text" id="booked_by" name="booked_by" class="form-control" required
              placeholder="Enter name or member ID">
          </div>

          <div class="form-group">
            <label for="member_id">Member (Optional)</label>
            <select id="member_id" name="member_id" class="form-control">
              <option value="">Select Member (Optional)</option>
              <?php foreach ($members_list as $member): ?>
                <option value="<?php echo $member['id']; ?>">
                  <?php echo htmlspecialchars($member['head_name'] . ' (M' . $member['member_number'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="purpose">Purpose *</label>
            <input type="text" id="purpose" name="purpose" class="form-control" required
              placeholder="e.g., Wedding, Conference, Class">
          </div>

          <div class="form-group">
            <label for="requirements">Additional Requirements</label>
            <textarea id="requirements" name="requirements" class="form-control" rows="3"
              placeholder="Any special requirements or notes"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('bookAssetModal')">Cancel</button>
        <button type="submit" form="bookAssetForm" class="btn btn-primary">
          <i class="fas fa-check"></i> Submit Booking Request
        </button>
      </div>
    </div>
  </div>

  <!-- Add Category Modal -->
  <div class="modal-overlay" id="addCategoryModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-tag"></i> Add Asset Category</h3>
        <button class="close-modal" onclick="closeModal('addCategoryModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addCategoryForm" method="POST">
          <input type="hidden" name="action" value="add_category">
          <div class="form-group">
            <label for="category_name">Category Name *</label>
            <input type="text" id="category_name" name="category_name" class="form-control" required
              placeholder="e.g., Office Equipment, Prayer Hall Items">
          </div>

          <div class="form-group">
            <label for="category_description">Description</label>
            <textarea id="category_description" name="category_description" class="form-control" rows="3"
              placeholder="Describe this category"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCategoryModal')">Cancel</button>
        <button type="submit" form="addCategoryForm" class="btn btn-primary">
          <i class="fas fa-save"></i> Save Category
        </button>
      </div>
    </div>
  </div>

  <!-- View Asset Modal -->
  <div class="modal-overlay" id="viewAssetModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="fas fa-eye"></i> Asset Details</h3>
        <button class="close-modal" onclick="closeModal('viewAssetModal')">&times;</button>
      </div>
      <div class="modal-body" id="viewAssetContent">
        <!-- Content will be loaded via AJAX -->
        <div style="text-align: center; padding: 40px;">
          <div
            style="width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px;">
          </div>
          <p>Loading asset details...</p>
        </div>
        <style>
          @keyframes spin {
            to {
              transform: rotate(360deg);
            }
          }
        </style>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('viewAssetModal')">Close</button>
        <button type="button" class="btn btn-primary" onclick="printAssetDetails()">
          <i class="fas fa-print"></i> Print
        </button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Sidebar functionality
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('sidebarClose');

    function togglePurchaseFields() {
      const assetType = document.querySelector('input[name="asset_type"]:checked').value;
      const purchaseFields = document.getElementById('purchaseFields');

      if (assetType === 'new_purchase') {
        purchaseFields.style.display = 'block';
        // Make purchase fields required
        document.getElementById('acquisition_date').required = true;
        document.getElementById('purchase_cost').required = true;
      } else {
        purchaseFields.style.display = 'none';
        // Make purchase fields optional
        document.getElementById('acquisition_date').required = false;
        document.getElementById('purchase_cost').required = false;
        // Clear values
        document.getElementById('acquisition_date').value = '';
        document.getElementById('vendor_donor').value = '';
        document.getElementById('purchase_cost').value = '';
        // Removed resetting of taxable_amount so it stays available
      }
    }

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

    // Section navigation
    function showSection(sectionName) {
      // Hide all sections
      document.querySelectorAll('.content-section').forEach(section => {
        section.classList.remove('active');
      });

      // Show selected section
      const selectedSection = document.getElementById(sectionName + '-section');
      if (selectedSection) {
        selectedSection.classList.add('active');
      }

      // Update active nav link
      document.querySelectorAll('.menu-btn').forEach(btn => {
        btn.classList.remove('active');
      });

      // Find and activate the corresponding menu button
      const activeBtn = document.querySelector(`.menu-btn[onclick*="asset_management.php"]`);
      if (activeBtn) {
        activeBtn.classList.add('active');
      }

      // Scroll to top of the section
      window.scrollTo({ top: 0, behavior: 'smooth' });

      // Load charts if reports section
      if (sectionName === 'reports') {
        setTimeout(loadCharts, 300);
      }
    }

    // Tab Management for Maintenance
    function showMaintenanceTab(tabName) {
      document.querySelectorAll('#maintenanceTable tbody tr').forEach(row => {
        const status = row.getAttribute('data-status');
        let show = false;

        switch (tabName) {
          case 'upcoming':
            show = status === 'scheduled';
            break;
          case 'inprogress':
            show = status === 'in_progress';
            break;
          case 'completed':
            show = status === 'completed';
            break;
          case 'all':
            show = true;
            break;
        }

        row.style.display = show ? '' : 'none';
      });

      // Update active tab
      document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
      event.target.classList.add('active');
    }

    // Add these functions to your existing JavaScript code in asset_management.php

    // Toggle Taxable Amount
    function toggleTaxableAmount(mode) {
      const checkboxId = mode === 'add' ? 'is_taxable' : 'edit_is_taxable';
      const inputId = mode === 'add' ? 'taxable_amount' : 'edit_taxable_amount';

      const checkbox = document.getElementById(checkboxId);
      const input = document.getElementById(inputId);

      if (checkbox.checked) {
        input.readOnly = false;
        if (parseFloat(input.value) === 0) {
          input.value = ''; // Clear 0 to allow typing
        }
        input.focus();
      } else {
        input.readOnly = true;
        input.value = '0.00';
      }
    }

    // Edit Asset function
    function editAsset(assetId) {
      // Fetch asset details via AJAX
      fetch(`get_asset_details.php?asset_id=${assetId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Populate the edit form fields
            document.getElementById('edit_asset_id').value = data.asset.id;
            document.getElementById('edit_asset_name').value = data.asset.name;
            document.getElementById('edit_category_id').value = data.asset.category_id;
            document.getElementById('edit_acquisition_date').value = data.asset.acquisition_date;
            document.getElementById('edit_vendor_donor').value = data.asset.vendor_donor || '';
            document.getElementById('edit_purchase_cost').value = data.asset.purchase_cost;
            document.getElementById('edit_current_value').value = data.asset.current_value;

            // Handle Taxable Amount Logic
            const taxableAmount = parseFloat(data.asset.taxable_amount);
            document.getElementById('edit_taxable_amount').value = taxableAmount;

            const isTaxableCbx = document.getElementById('edit_is_taxable');
            const taxableInput = document.getElementById('edit_taxable_amount');

            if (taxableAmount > 0) {
              isTaxableCbx.checked = true;
              taxableInput.readOnly = false;
            } else {
              isTaxableCbx.checked = false;
              taxableInput.readOnly = true;
            }

            document.getElementById('edit_rental_status').value = data.asset.rental_status;
            document.getElementById('edit_condition_status').value = data.asset.condition_status;
            document.getElementById('edit_asset_status').value = data.asset.status;
            document.getElementById('edit_location').value = data.asset.location || '';
            document.getElementById('edit_assigned_to').value = data.asset.assigned_to || '';
            document.getElementById('edit_maintenance_frequency').value = data.asset.maintenance_frequency || '';
            document.getElementById('edit_description').value = data.asset.description || '';
            document.getElementById('edit_notes').value = data.asset.notes || '';

            // Open the edit modal
            openModal('editAssetModal');
          } else {
            alert('Error loading asset details: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('Failed to load asset details. Please try again.');
        });
    }

    // View Asset function
    function viewAsset(assetId) {
      // Fetch asset details via AJAX
      fetch(`asset_ajax.php?action=view_asset&id=${assetId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Create HTML content for the view modal
            const content = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Asset Code</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px; font-weight: bold;">${data.asset.asset_code}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Asset Name</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.name}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Category</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.category_name}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Acquisition Date</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${new Date(data.asset.acquisition_date).toLocaleDateString()}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Purchase Cost</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">₹${parseFloat(data.asset.purchase_cost).toFixed(2)}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Current Value</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">₹${parseFloat(data.asset.current_value).toFixed(2)}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Taxable Amount</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">₹${parseFloat(data.asset.taxable_amount).toFixed(2)}</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Rental Status</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                                    <span class="badge ${data.asset.rental_status === 'rental' ? 'badge-info' : 'badge-secondary'}">
                                        ${data.asset.rental_status === 'rental' ? 'RENTAL' : 'NON-RENTAL'}
                                    </span>
                                </p>
                            </div>
                            
                            <div class="form-group">
                                <label>Condition</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                                    <span class="badge ${getConditionBadgeClass(data.asset.condition_status)}">
                                        ${data.asset.condition_status.replace('_', ' ').toUpperCase()}
                                    </span>
                                </p>
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                                    <span class="badge ${getStatusBadgeClass(data.asset.status)}">
                                        ${data.asset.status.toUpperCase()}
                                    </span>
                                </p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <h4 style="margin-bottom: 10px;">Additional Information</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label>Location</label>
                                    <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.location || 'Not specified'}</p>
                                </div>
                                
                                <div class="form-group">
                                    <label>Vendor/Donor</label>
                                    <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.vendor_donor || 'Not specified'}</p>
                                </div>
                                
                                <div class="form-group">
                                    <label>Maintenance Frequency</label>
                                    <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.maintenance_frequency || 'Not specified'}</p>
                                </div>
                                
                                <div class="form-group">
                                    <label>Created On</label>
                                    <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${new Date(data.asset.created_at).toLocaleString()}</p>
                                </div>
                            </div>
                            
                            <div class="form-group" style="margin-top: 15px;">
                                <label>Description</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.description || 'No description'}</p>
                            </div>
                            
                            <div class="form-group" style="margin-top: 15px;">
                                <label>Notes</label>
                                <p style="margin: 5px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">${data.asset.notes || 'No notes'}</p>
                            </div>
                        </div>
                    `;

            document.getElementById('viewAssetContent').innerHTML = content;
            openModal('viewAssetModal');
          } else {
            alert('Error loading asset details: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          document.getElementById('viewAssetContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #e74c3c;"><i class="fas fa-exclamation-circle" style="font-size: 48px; margin-bottom: 20px;"></i><p>Failed to load asset details. Please try again.</p></div>';
          openModal('viewAssetModal');
        });
    }

    // Delete Asset function
    function deleteAsset(assetId, assetName) {
      if (confirm(`Are you sure you want to delete asset "${assetName}"? This action cannot be undone.`)) {
        fetch(`asset_ajax.php?action=delete_asset&id=${assetId}`)
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert(data.message);
              location.reload();
            } else {
              alert('Error: ' + data.message);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('Failed to delete asset. Please try again.');
          });
      }
    }

    // Delete Category function
    function deleteCategory(categoryId, categoryName) {
      if (confirm(`Are you sure you want to delete category "${categoryName}"? This action cannot be undone.`)) {
        fetch(`asset_ajax.php?action=delete_category&id=${categoryId}`)
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert(data.message);
              location.reload();
            } else {
              alert('Error: ' + data.message);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('Failed to delete category. Please try again.');
          });
      }
    }

    // Update Maintenance Status function
    function updateMaintenanceStatus(maintenanceId, status) {
      let statusText = status === 'completed' ? 'complete' : status.replace('_', ' ');
      if (confirm(`Are you sure you want to mark this maintenance as ${statusText}?`)) {
        fetch(`asset_ajax.php?action=update_maintenance_status&id=${maintenanceId}&status=${status}`)
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert(data.message);
              location.reload();
            } else {
              alert('Error: ' + data.message);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('Failed to update maintenance status. Please try again.');
          });
      }
    }

    // Update Booking Status function
    function updateBookingStatus(bookingId, status) {
      if (confirm(`Are you sure you want to ${status} this booking?`)) {
        fetch(`asset_ajax.php?action=update_booking_status&id=${bookingId}&status=${status}`)
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert(data.message);
              location.reload();
            } else {
              alert('Error: ' + data.message);
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('Failed to update booking status. Please try again.');
          });
      }
    }

    // Helper functions for badge classes
    function getConditionBadgeClass(condition) {
      switch (condition) {
        case 'excellent': return 'badge-success';
        case 'good': return 'badge-success';
        case 'fair': return 'badge-warning';
        case 'needs_repair': return 'badge-danger';
        case 'out_of_service': return 'badge-secondary';
        default: return 'badge-info';
      }
    }

    function getStatusBadgeClass(status) {
      switch (status) {
        case 'active': return 'badge-success';
        case 'inactive': return 'badge-warning';
        case 'disposed': return 'badge-secondary';
        case 'lost': return 'badge-danger';
        default: return 'badge-info';
      }
    }

    // Tab Management for Bookings
    function showBookingsTab(tabName) {
      document.querySelectorAll('#bookingsTable tbody tr').forEach(row => {
        const status = row.getAttribute('data-status');
        let show = false;

        switch (tabName) {
          case 'pending':
            show = status === 'pending';
            break;
          case 'approved':
            show = status === 'approved';
            break;
          case 'rejected':
            show = status === 'rejected';
            break;
          case 'all':
            show = true;
            break;
        }

        row.style.display = show ? '' : 'none';
      });

      // Update active tab
      document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
      event.target.classList.add('active');
    }

    // Modal Management
    function openModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'flex';

        // Set default dates
        const today = new Date().toISOString().split('T')[0];
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 7);
        const nextWeek = tomorrow.toISOString().split('T')[0];

        const dateFields = modal.querySelectorAll('input[type="date"]');
        dateFields.forEach(field => {
          if (!field.value) {
            if (field.id === 'due_date' || field.id === 'acquisition_date') {
              field.value = nextWeek;
            } else {
              field.value = today;
            }
          }
        });
      }
    }

    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'none';
        // Reset form if exists
        const form = modal.querySelector('form');
        if (form) form.reset();
      }
    }

    // Close modal when clicking outside
    document.addEventListener('click', function (event) {
      if (event.target.classList.contains('modal-overlay')) {
        event.target.style.display = 'none';
        // Reset form if exists
        const form = event.target.querySelector('form');
        if (form) form.reset();
      }
    });

    // Initialize navigation
    document.addEventListener('DOMContentLoaded', function () {
      // Set default dates for forms
      const today = new Date().toISOString().split('T')[0];
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 7);
      const nextWeek = tomorrow.toISOString().split('T')[0];

      document.querySelectorAll('input[type="date"]').forEach(field => {
        if (!field.value) {
          if (field.id === 'due_date' || field.id === 'acquisition_date') {
            field.value = nextWeek;
          } else {
            field.value = today;
          }
        }
      });

      // Add form validation
      document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
          const requiredFields = form.querySelectorAll('[required]');
          let isValid = true;

          requiredFields.forEach(field => {
            if (!field.value.trim()) {
              isValid = false;
              field.style.borderColor = 'var(--error)';
            } else {
              field.style.borderColor = '';
            }
          });

          if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields marked with *');
          }
        });
      });
    });

    // Search functionality
    function searchTable(tableId, query) {
      const table = document.getElementById(tableId);
      const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
      query = query.toLowerCase();

      for (let row of rows) {
        const cells = row.getElementsByTagName('td');
        let found = false;

        for (let cell of cells) {
          if (cell.textContent.toLowerCase().includes(query)) {
            found = true;
            break;
          }
        }

        row.style.display = found ? '' : 'none';
      }
    }

    // Filter assets by category and rental status
    function filterAssets() {
      const categoryFilter = document.getElementById('filterCategory').value;
      const rentalFilter = document.getElementById('filterRental').value;
      const rows = document.querySelectorAll('#assetsTable tbody tr');

      rows.forEach(row => {
        const category = row.getAttribute('data-category');
        const rental = row.getAttribute('data-rental');
        let show = true;

        if (categoryFilter && category !== categoryFilter) show = false;
        if (rentalFilter && rental !== rentalFilter) show = false;

        row.style.display = show ? '' : 'none';
      });
    }

    // View Category Assets function
    function viewCategoryAssets(categoryId, categoryName) {
      // Redirect to category details page with parameters
      window.location.href = `category_assets.php?category_id=${categoryId}&category_name=${encodeURIComponent(categoryName)}`;
    }

    // Make quick access cards clickable
    document.querySelectorAll('.quick-access-card').forEach(card => {
      card.addEventListener('click', function () {
        if (this.classList.contains('inventory')) {
          showSection('inventory');
        } else if (this.classList.contains('bookings')) {
          showSection('bookings');
        }
      });
    });

    // Chart functions
    function loadCharts() {
      // Fetch report data via AJAX
      fetch('asset_ajax.php?action=get_reports_data')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            createCategoryChart(data.data.category_values);
            createMaintenanceChart(data.data.maintenance_stats);
            createBookingChart(data.data.booking_stats);
            createConditionChart(data.data.condition_stats);
          }
        })
        .catch(error => {
          console.error('Error loading chart data:', error);
        });
    }

    function createCategoryChart(data) {
      const ctx = document.getElementById('categoryChart').getContext('2d');
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: data.map(item => item.category_name),
          datasets: [{
            data: data.map(item => item.total_value),
            backgroundColor: [
              '#4a6fa5', '#6bbaa7', '#f18f8f', '#f59e0b', '#3498db',
              '#9b59b6', '#1abc9c', '#34495e'
            ]
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'bottom'
            }
          }
        }
      });
    }

    function createMaintenanceChart(data) {
      const ctx = document.getElementById('maintenanceChart').getContext('2d');
      new Chart(ctx, {
        type: 'pie',
        data: {
          labels: data.map(item => item.status),
          datasets: [{
            data: data.map(item => item.count),
            backgroundColor: ['#4a6fa5', '#f59e0b', '#27ae60', '#e74c3c']
          }]
        }
      });
    }

    function createBookingChart(data) {
      const ctx = document.getElementById('bookingChart').getContext('2d');
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.map(item => item.month),
          datasets: [{
            label: 'Bookings',
            data: data.map(item => item.count),
            borderColor: '#4a6fa5',
            backgroundColor: 'rgba(74, 111, 165, 0.1)',
            fill: true
          }]
        }
      });
    }

    function createConditionChart(data) {
      const ctx = document.getElementById('conditionChart').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.map(item => item.condition_status),
          datasets: [{
            label: 'Assets',
            data: data.map(item => item.count),
            backgroundColor: '#6bbaa7'
          }]
        }
      });
    }

    function exportReport(format) {
      alert(`${format.toUpperCase()} export would be generated here. This is a demo.`);
    }

    function printReport() {
      window.print();
    }

    function printAssetDetails() {
      const printContent = document.getElementById('viewAssetContent').innerHTML;
      const originalContent = document.body.innerHTML;
      document.body.innerHTML = printContent;
      window.print();
      document.body.innerHTML = originalContent;
      location.reload();
    }

    function updateMaxQuantity(selectElement) {
      const selectedOption = selectElement.options[selectElement.selectedIndex];
      const maxQty = selectedOption.getAttribute('data-max');
      const qtyInput = document.getElementById('booking_quantity');
      const hintText = document.getElementById('available_qty_hint');

      if (maxQty) {
        qtyInput.max = maxQty;
        hintText.textContent = `Total in inventory: ${maxQty} (Availability depends on dates)`;
      } else {
        qtyInput.removeAttribute('max');
        hintText.textContent = 'Select an asset to see availability';
      }
    }
  </script>

</body>

</html>