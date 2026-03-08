<?php
// edit_asset.php
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
$conn = $db_result['conn'];

// Get asset ID from URL
$asset_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($asset_id <= 0) {
    header("Location: asset_management.php");
    exit();
}

// Get logged-in user details
$user_id = $_SESSION['user_id'];

// Get user details for header and sidebar
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

// Fetch categories for the form
$stmt = $conn->prepare("SELECT id, category_name FROM asset_categories WHERE mahal_id = ? AND status = 'active' ORDER BY category_name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$categories_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch staff for assignment
$stmt = $conn->prepare("SELECT id, name, staff_id FROM staff WHERE mahal_id = ? AND salary_status = 'active' ORDER BY name");
$stmt->bind_param("i", $mahal_id);
$stmt->execute();
$staff_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch initial asset data for form population (will be loaded via AJAX)
$stmt = $conn->prepare("SELECT name, asset_code FROM assets WHERE id = ? AND mahal_id = ? LIMIT 1");
$stmt->bind_param("ii", $asset_id, $mahal_id);
$stmt->execute();
$result = $stmt->get_result();
$initial_asset_data = $result->fetch_assoc();
$stmt->close();

if (!$initial_asset_data) {
    echo "<script>alert('Asset not found or you do not have permission to edit it.'); window.location.href='asset_management.php';</script>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Asset - <?php echo htmlspecialchars($initial_asset_data['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
        }

        .card-header h3 {
            color: var(--text);
            font-size: 18px;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 111, 165, 0.3);
        }

        .btn-secondary {
            background: var(--text-light);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(127, 140, 141, 0.3);
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .loading-spinner i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: none;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.15);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.15);
            color: var(--error);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>Edit Asset: <?php echo htmlspecialchars($initial_asset_data['name']); ?></h1>
                <p style="opacity: 0.9; margin-top: 5px;">Asset Code: <?php echo htmlspecialchars($initial_asset_data['asset_code']); ?></p>
            </div>
            <button class="btn back-btn" onclick="window.location.href='asset_details.php?id=<?php echo $asset_id; ?>'">
                <i class="fas fa-arrow-left"></i> Back to Details
            </button>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading asset data...</p>
        </div>

        <!-- Alerts -->
        <div id="successAlert" class="alert alert-success" style="display: none;">
            <i class="fas fa-check-circle"></i> Asset updated successfully!
        </div>
        <div id="errorAlert" class="alert alert-error" style="display: none;">
            <i class="fas fa-exclamation-circle"></i> <span id="errorMessage"></span>
        </div>

        <!-- Edit Form -->
        <div id="editForm" class="card" style="display: none;">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Edit Asset Details</h3>
                <button class="btn btn-secondary" onclick="window.location.href='asset_details.php?id=<?php echo $asset_id; ?>'">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            
            <form id="assetEditForm" method="POST">
                <input type="hidden" name="asset_id" id="asset_id" value="<?php echo $asset_id; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="asset_name">Asset Name *</label>
                        <input type="text" id="asset_name" name="asset_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="category_id">Category *</label>
                        <select id="category_id" name="category_id" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories_list as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="acquisition_date">Acquisition Date *</label>
                        <input type="date" id="acquisition_date" name="acquisition_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="vendor_donor">Vendor/Donor</label>
                        <input type="text" id="vendor_donor" name="vendor_donor" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="purchase_cost">Purchase Cost (₹) *</label>
                        <input type="number" id="purchase_cost" name="purchase_cost" class="form-control" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="current_value">Current Value (₹)</label>
                        <input type="number" id="current_value" name="current_value" class="form-control" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="condition_status">Condition *</label>
                        <select id="condition_status" name="condition_status" class="form-control" required>
                            <option value="">Select Condition</option>
                            <option value="excellent">Excellent</option>
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="needs_repair">Needs Repair</option>
                            <option value="out_of_service">Out of Service</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="asset_status">Status *</label>
                        <select id="asset_status" name="asset_status" class="form-control" required>
                            <option value="">Select Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="disposed">Disposed</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                </div>
                
                <!-- ADDED: Rental Status Field -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="rental_status">Rental Status *</label>
                        <select id="rental_status" name="rental_status" class="form-control" required>
                            <option value="">Select Rental Status</option>
                            <option value="rental">Rental</option>
                            <option value="non-rental">Non-Rental</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="assigned_to">Assigned To</label>
                        <select id="assigned_to" name="assigned_to" class="form-control">
                            <option value="">Not Assigned</option>
                            <?php foreach ($staff_list as $staff): ?>
                                <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name'] . ' (' . $staff['staff_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="maintenance_frequency">Maintenance Frequency</label>
                        <select id="maintenance_frequency" name="maintenance_frequency" class="form-control">
                            <option value="">Select Frequency</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-annual</option>
                            <option value="annual">Annual</option>
                            <option value="as_needed">As Needed</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="notes">Internal Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='asset_details.php?id=<?php echo $asset_id; ?>'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const assetId = <?php echo $asset_id; ?>;
            const editForm = document.getElementById('editForm');
            const loadingState = document.getElementById('loadingState');
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            const errorMessage = document.getElementById('errorMessage');
            const submitBtn = document.getElementById('submitBtn');
            const originalBtnText = submitBtn.innerHTML;

            // Load asset data via AJAX
            fetch(`get_asset_details.php?asset_id=${assetId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.asset) {
                        const asset = data.asset;
                        
                        // Populate form fields
                        document.getElementById('asset_name').value = asset.name || '';
                        document.getElementById('category_id').value = asset.category_id || '';
                        document.getElementById('acquisition_date').value = asset.acquisition_date || '';
                        document.getElementById('vendor_donor').value = asset.vendor_donor || '';
                        document.getElementById('purchase_cost').value = asset.purchase_cost || 0;
                        document.getElementById('current_value').value = asset.current_value || asset.purchase_cost || 0;
                        document.getElementById('condition_status').value = asset.condition_status || '';
                        document.getElementById('asset_status').value = asset.status || '';
                        document.getElementById('rental_status').value = asset.rental_status || 'non-rental'; // ADDED
                        document.getElementById('location').value = asset.location || '';
                        document.getElementById('assigned_to').value = asset.assigned_to || '';
                        document.getElementById('maintenance_frequency').value = asset.maintenance_frequency || '';
                        document.getElementById('description').value = asset.description || '';
                        document.getElementById('notes').value = asset.notes || '';
                        
                        // Show form, hide loading
                        loadingState.style.display = 'none';
                        editForm.style.display = 'block';
                    } else {
                        loadingState.innerHTML = `
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Error loading asset data: ${data.message || 'Unknown error'}</p>
                            <button class="btn btn-secondary" onclick="window.history.back()">
                                <i class="fas fa-arrow-left"></i> Go Back
                            </button>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading asset data:', error);
                    loadingState.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Failed to load asset data. Please try again.</p>
                        <button class="btn btn-secondary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Go Back
                        </button>
                    `;
                });

            // Handle form submission
            document.getElementById('assetEditForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                // Show loading state on button
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                
                // Hide previous alerts
                successAlert.style.display = 'none';
                errorAlert.style.display = 'none';
                
                fetch('update_asset.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        successAlert.style.display = 'block';
                        
                        // Redirect after 2 seconds
                        setTimeout(() => {
                            window.location.href = `asset_details.php?id=${assetId}`;
                        }, 2000);
                    } else {
                        // Show error message
                        errorMessage.textContent = data.message || 'Failed to update asset';
                        errorAlert.style.display = 'block';
                        
                        // Scroll to error
                        errorAlert.scrollIntoView({ behavior: 'smooth' });
                    }
                })
                .catch(error => {
                    console.error('Error updating asset:', error);
                    errorMessage.textContent = 'Network error. Please try again.';
                    errorAlert.style.display = 'block';
                    errorAlert.scrollIntoView({ behavior: 'smooth' });
                })
                .finally(() => {
                    // Reset button state
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                });
            });
        });
    </script>
</body>
</html>