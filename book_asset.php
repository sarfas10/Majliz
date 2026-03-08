<?php
// book_asset.php - Standalone Asset Booking Page
require_once __DIR__ . '/session_bootstrap.php';

/* --- auth gate --- */
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'db_connection.php';
$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed: " . $db_result['error']);
}
$conn = $db_result['conn'];

$user_id = $_SESSION['user_id'];
$mahal_id = $user_id; // Using user_id as mahal_id as per session logic

// Fetch User/Mahal Details for Header
$stmt = $conn->prepare("SELECT name FROM register WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($mahal_name);
$stmt->fetch();
$stmt->close();

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_asset') {
    try {
        $asset_name = $_POST['asset_name'];
        $quantity = intval($_POST['quantity']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $booking_amount = !empty($_POST['booking_amount']) ? floatval($_POST['booking_amount']) * $quantity : 0.00; // Total or per item? Usually per item in form, but let's assume input is TOTAL or PER ITEM?
        // Logic in previous file: $booking_amount was passed directly to EACH booking. So it is PER ITEM/BOOKING.
        $booking_amount_per_item = !empty($_POST['booking_amount']) ? floatval($_POST['booking_amount']) : 0.00;
        
        $booked_for = !empty($_POST['member_id']) ? intval($_POST['member_id']) : NULL;
        $attendees = !empty($_POST['attendees']) ? intval($_POST['attendees']) : 0;
        $requirements = $_POST['requirements'] ?? '';
        $purpose = $_POST['purpose'];
        $booked_by_name = $_POST['booked_by'];

        // 1. Find assets with this name
        $stmt = $conn->prepare("SELECT id FROM assets WHERE name = ? AND rental_status = 'rental' AND status = 'active' AND mahal_id = ?");
        $stmt->bind_param("si", $asset_name, $mahal_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $candidate_asset_ids = [];
        while ($row = $res->fetch_assoc()) {
            $candidate_asset_ids[] = $row['id'];
        }
        $stmt->close();

        if (empty($candidate_asset_ids)) {
            throw new Exception("No rental assets found with name: " . $asset_name);
        }

        // 2. Filter for availability
        $available_assets = [];
        foreach ($candidate_asset_ids as $aid) {
            // Check overlap
            $check_stmt = $conn->prepare("
                SELECT COUNT(*) as cnt 
                FROM asset_bookings 
                WHERE asset_id = ? 
                AND status = 'approved'
                AND (
                    (start_date <= ? AND end_date >= ?) OR
                    (start_date <= ? AND end_date >= ?) OR
                    (start_date >= ? AND end_date <= ?)
                )
            ");
            $check_stmt->bind_param("issssss", $aid, $end_date, $start_date, $start_date, $start_date, $start_date, $end_date);
            $check_stmt->execute();
            $check_res = $check_stmt->get_result();
            $check_row = $check_res->fetch_assoc();
            $check_stmt->close();

            if ($check_row['cnt'] == 0) {
                $available_assets[] = $aid;
            }
            if (count($available_assets) >= $quantity) break; 
        }

        if (count($available_assets) < $quantity) {
            echo "<script>alert('Error: Only " . count($available_assets) . " items available for the selected dates.');</script>";
        } else {
            // 3. Book them
            $assets_to_book = array_slice($available_assets, 0, $quantity);
            $stmt = $conn->prepare("INSERT INTO asset_bookings (mahal_id, asset_id, start_date, end_date, booking_amount, booked_by, booked_for, purpose, requirements, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $success_count = 0;
            foreach ($assets_to_book as $asset_id_to_book) {
                 $stmt->bind_param(
                    "iissdsissi",
                    $mahal_id,
                    $asset_id_to_book,
                    $start_date,
                    $end_date,
                    $booking_amount_per_item,
                    $booked_by_name,
                    $booked_for,
                    $purpose,
                    $requirements,
                    $user_id
                );
                if ($stmt->execute()) $success_count++;
            }
            $stmt->close();

            if ($success_count == $quantity) {
                echo "<script>
                    alert('Booking successfully created for $success_count items!');
                    window.location.href = 'asset_management.php'; // Redirect back to dashboard
                </script>";
                exit;
            } else {
                 echo "<script>alert('Warning: Only booked $success_count items.');</script>";
            }
        }

    } catch (Exception $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// --- Fetch Data for Dropdowns ---

// 1. Rental Assets (Grouped)
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

// 2. Members
$members_list = [];
try {
    // Check if status column exists in members
    $columns_result = $conn->query("DESCRIBE members");
    $has_status = false;
    if ($columns_result) {
        while ($col = $columns_result->fetch_assoc()) {
            if ($col['Field'] == 'status') { $has_status = true; break; }
        }
    }
    
    $query = $has_status 
        ? "SELECT id, head_name, member_number FROM members WHERE mahal_id = ? AND status = 'active' ORDER BY head_name"
        : "SELECT id, head_name, member_number FROM members WHERE mahal_id = ? ORDER BY head_name";
        
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $mahal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members_list[] = $row;
    }
    $stmt->close();
} catch (Exception $e) { /* Ignore */ }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking - <?php echo htmlspecialchars($mahal_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4a6fa5;
            --primary-dark: #3a5984;
            --secondary: #6bbaa7;
            --accent: #f18f8f;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #2c3e50;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .container {
            width: 100%;
            max-width: 600px;
            background: var(--card);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-top: 20px;
        }

        header {
            margin-bottom: 30px;
            text-align: center;
        }

        h1 {
            color: var(--primary);
            font-size: 24px;
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            box-sizing: border-box; 
            transition: all 0.2s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 111, 165, 0.1);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 14px;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            text-align: center;
            transition: transform 0.1s;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: transparent;
            color: #718096;
            margin-top: 10px;
            padding: 10px;
            border: 1px solid var(--border);
        }
        
        .btn-cancel:hover {
            background: #f1f5f9;
            color: var(--text);
        }

        .hint {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div style="font-size: 40px; color: var(--primary); margin-bottom: 10px;">
            <i class="fas fa-book-open"></i>
        </div>
        <h1>New Asset Booking</h1>
        <p>Book assets for events or members</p>
    </header>

    <form method="POST" id="bookingForm">
        <input type="hidden" name="action" value="book_asset">

        <div class="form-group">
            <label for="booking_asset_name">Select Asset *</label>
            <select id="booking_asset_name" name="asset_name" required onchange="updateMaxQuantity(this)">
                <option value="" data-max="0">Select Asset</option>
                <?php foreach ($rental_assets_list as $asset): ?>
                    <option value="<?php echo htmlspecialchars($asset['name']); ?>" data-max="<?php echo $asset['asset_count']; ?>">
                        <?php echo htmlspecialchars($asset['name'] . ' (Total: ' . $asset['asset_count'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="booking_quantity">Quantity *</label>
            <input type="number" id="booking_quantity" name="quantity" required min="1" value="1">
            <small id="available_qty_hint" class="hint">Select an asset to see availability</small>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="start_date">Start Date *</label>
                <input type="date" id="start_date" name="start_date" required>
            </div>
            <div class="form-group">
                <label for="end_date">End Date *</label>
                <input type="date" id="end_date" name="end_date" required>
            </div>
        </div>

        <div class="form-group">
            <label for="purpose">Purpose *</label>
            <input type="text" id="purpose" name="purpose" required placeholder="e.g., Wedding, Conference">
        </div>

        <div class="form-group">
            <label for="booked_by">Booked By (Name) *</label>
            <input type="text" id="booked_by" name="booked_by" required placeholder="Name of person booking">
        </div>

        <div class="form-group">
            <label for="member_id">Member (Optional)</label>
            <select id="member_id" name="member_id">
                <option value="">Select Member (if applicable)</option>
                <?php foreach ($members_list as $member): ?>
                    <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['head_name'] . ' (#' . $member['member_number'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="hint">Link this booking to a registered member</small>
        </div>

        <div class="grid-2">
             <div class="form-group">
                <label for="booking_amount">Amount Per Item (₹)</label>
                <input type="number" id="booking_amount" name="booking_amount" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label for="attendees">Est. Attendees</label>
                <input type="number" id="attendees" name="attendees" placeholder="0">
            </div>
        </div>

        <div class="form-group">
            <label for="requirements">Notes / Requirements</label>
            <textarea id="requirements" name="requirements" rows="3"></textarea>
        </div>

        <button type="submit" class="btn">
            <i class="fas fa-check"></i> Confirm Booking
        </button>

        <button type="button" class="btn btn-cancel" onclick="window.location.href='asset_management.php'">
            Cancel & Return
        </button>
    </form>
</div>

<script>
    // Set default dates
    document.addEventListener('DOMContentLoaded', () => {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('start_date').value = today;
        document.getElementById('end_date').value = today;
    });

    function updateMaxQuantity(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const maxQty = selectedOption.getAttribute('data-max');
        const qtyInput = document.getElementById('booking_quantity');
        const hintText = document.getElementById('available_qty_hint');
        
        if (maxQty) {
            qtyInput.max = maxQty;
            hintText.textContent = `Total in inventory: ${maxQty} (Availability checked upon submission)`;
            hintText.style.color = 'var(--primary)';
        } else {
            qtyInput.removeAttribute('max');
            hintText.textContent = 'Select an asset to see availability';
        }
    }
</script>

</body>
</html>
