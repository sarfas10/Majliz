<?php
// member_receipt.php - Receipt viewer for members

/* --- secure session --- */
require_once __DIR__ . '/session_bootstrap.php';

/* --- no-cache headers --- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/* --- auth gate: only logged-in members may access --- */
if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header("Location: index.php");
    exit();
}

/* --- DB --- */
require_once __DIR__ . '/db_connection.php';

// Get transaction ID and user_id from query parameters
$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($transaction_id <= 0 || $user_id <= 0) {
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid receipt request.";
    exit();
}

$memberSess = $_SESSION['member'];
$member_mahal_id = (int)$memberSess['mahal_id'];

// Verify the receipt belongs to this member's mahal
if ($user_id !== $member_mahal_id) {
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Access denied. This receipt does not belong to your mahal.";
    exit();
}

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed: " . $db_result['error']);
    }
    /** @var mysqli $conn */
    $conn = $db_result['conn'];

    // Check if the member has access to this transaction
    // For regular members: check if transaction is linked to their household head or family members
    // For sahakari members: check if transaction is linked to their sahakari member ID
    
    $is_sahakari = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'sahakari_head');
    
    if ($is_sahakari) {
        // For Sahakari members, check if transaction belongs to their sahakari_member_id
        $stmt = $conn->prepare("
            SELECT t.*, s.head_name, s.member_number, s.address,
                   r.name AS mahal_name, r.address AS mahal_address, 
                   r.registration_no AS reg_no, r.email AS mahal_email
            FROM transactions t
            LEFT JOIN sahakari_members s ON t.donor_member_id = s.id
            JOIN register r ON r.id = t.user_id
            WHERE t.id = ? 
              AND t.user_id = ?
              AND (t.donor_member_id IS NULL OR t.donor_member_id = ?)
            LIMIT 1
        ");
        $stmt->bind_param("iii", $transaction_id, $user_id, $memberSess['member_id']);
    } else {
        // For regular members, check if transaction belongs to their household
        $household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
            ? (int)$memberSess['parent_member_id']
            : (int)$memberSess['member_id'];
        
        $stmt = $conn->prepare("
            SELECT t.*, m.head_name, m.member_number, m.address,
                   r.name AS mahal_name, r.address AS mahal_address, 
                   r.registration_no AS reg_no, r.email AS mahal_email
            FROM transactions t
            LEFT JOIN members m ON t.donor_member_id = m.id
            JOIN register r ON r.id = t.user_id
            WHERE t.id = ? 
              AND t.user_id = ?
              AND (t.donor_member_id IS NULL OR t.donor_member_id = ?)
            LIMIT 1
        ");
        $stmt->bind_param("iii", $transaction_id, $user_id, $household_member_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Receipt not found or access denied.";
        exit();
    }
    
    $transaction = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

} catch (Throwable $e) {
    error_log("Member receipt error: " . $e->getMessage());
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error loading receipt.";
    exit();
}

// Prepare data for display
$mahal_name = htmlspecialchars($transaction['mahal_name'] ?? 'Mahal');
$mahal_address = htmlspecialchars($transaction['mahal_address'] ?? '');
$reg_no = htmlspecialchars($transaction['reg_no'] ?? '');
$mahal_email = htmlspecialchars($transaction['mahal_email'] ?? '');

$transaction_date = date('d-m-Y', strtotime($transaction['transaction_date']));
$type = htmlspecialchars($transaction['type']);
$category = htmlspecialchars($transaction['category']);
$docLabel = ($transaction['type'] === 'EXPENSE') ? 'Voucher' : 'Receipt';
$description = htmlspecialchars($transaction['description'] ?? '-');
$otherDetail = htmlspecialchars($transaction['other_expense_detail'] ?? '-');
$amount = number_format((float)$transaction['amount'], 2);
$donor_details = htmlspecialchars($transaction['donor_details'] ?? '-');

// Donor information
$donor_head_name = htmlspecialchars($transaction['head_name'] ?? '-');
$donor_member_no = isset($transaction['member_number']) ? (string)$transaction['member_number'] : '';
$donor_address = trim((string)($transaction['address'] ?? ''));

$payment_mode = htmlspecialchars($transaction['payment_mode'] ?? 'CASH');
$receipt_no = htmlspecialchars($transaction['receipt_no'] ?? '');

// Fallback receipt ID if no receipt number
$fallbackPrefix = ($transaction['type'] === 'EXPENSE') ? 'V' : 'R';
$receipt_display = !empty($receipt_no) ? $receipt_no : ($fallbackPrefix . $transaction['id']);

$title = $docLabel . " " . $receipt_display;

// Staff name if applicable
$staff_name = '';
if (!empty($transaction['staff_id'])) {
    // You might want to fetch staff name here if needed
    $staff_name = 'Staff Payment';
}

// Get current URL for sharing
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
$selfUrl = $origin . htmlspecialchars($_SERVER['REQUEST_URI']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $title ?> - <?= $mahal_name ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --ink: #111827;
            --muted: #6b7280;
            --bg: #ffffff;
            --line: #e5e7eb;
            --pri: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
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
            line-height: 1.5;
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

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
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
            font-family: inherit;
            font-size: 14px;
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

            html, body {
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
                height: 138.5mm; /* half of A4 (148.5mm) minus body padding (8mm top+8mm bottom offset) */
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
                <h1><?= $mahal_name ?></h1>
                <div>
                    <?php if ($mahal_address) echo $mahal_address . ' · '; ?>
                    <?php if ($reg_no) echo 'Reg: ' . $reg_no . ' · '; ?>
                    <?php if ($mahal_email) echo $mahal_email; ?>
                </div>
            </div>
            <div class="meta">
                <div><?= $title ?></div>
                <div>Date: <?= $transaction_date ?></div>
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
                    <div class="val"><?= $category ?></div>
                </div>
                <div class="row">
                    <div class="label">Description</div>
                    <div class="val desc"><?= $description ?></div>
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
                    <div class="val amt">₹<?= $amount ?></div>
                </div>
                <div class="row">
                    <div class="label">Payment Mode</div>
                    <div class="val"><?= $payment_mode ?></div>
                </div>
                <div class="row">
                    <div class="label"><?= $docLabel ?> ID</div>
                    <div class="val"><?= $receipt_display ?></div>
                </div>
                <div class="row">
                    <div class="label">Transaction Date</div>
                    <div class="val"><?= $transaction_date ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($donor_details) || !empty($donor_head_name)): ?>
            <div class="card">
                <h3>Donor Information</h3>
                <?php if (!empty($donor_details)): ?>
                    <div class="row">
                        <div class="label">Donor Name</div>
                        <div class="val"><?= $donor_details ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($donor_head_name)): ?>
                    <div class="row">
                        <div class="label">Household Head</div>
                        <div class="val"><?= $donor_head_name ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($donor_member_no)): ?>
                    <div class="row">
                        <div class="label">Member Number</div>
                        <div class="val"><?= htmlspecialchars($donor_member_no) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($donor_address)): ?>
                    <div class="row">
                        <div class="label">Address</div>
                        <div class="val"><?= htmlspecialchars($donor_address) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($staff_name): ?>
            <div class="card">
                <h3>Staff Recipient</h3>
                <div class="row">
                    <div class="label">Staff</div>
                    <div class="val"><?= $staff_name ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions">
            <button class="btn" onclick="window.print()">Print Receipt</button>
            <button class="btn" id="shareBtn">Share</button>
      
            <button class="btn" id="closeBtn">Close</button>
        </div>

        <div class="foot">
            This is a system-generated <?= strtolower($docLabel) ?> for your records.
            <br>Generated on: <?= date('d-m-Y H:i:s') ?>
        </div>
    </div>

    <script>
        const receiptUrl = <?= json_encode($selfUrl) ?>;
        const docLabel = <?= json_encode($docLabel) ?>;
        
        // Share button functionality
        document.getElementById('shareBtn').addEventListener('click', async () => {
            if (navigator.share) {
                try {
                    await navigator.share({ 
                        title: document.title, 
                        text: docLabel + ' for ' + <?= json_encode($mahal_name) ?>, 
                        url: receiptUrl 
                    });
                } catch (e) {
                    console.log('Share cancelled');
                }
            } else {
                // Fallback: copy to clipboard
                try {
                    await navigator.clipboard.writeText(receiptUrl);
                    alert('Link copied to clipboard!');
                } catch (e) {
                    alert('Share not supported on this device.');
                }
            }
        });
        
      
        
        // Close button
        document.getElementById('closeBtn').addEventListener('click', () => {
            window.close();
        });
        
        // Auto-close after print
        window.addEventListener('afterprint', () => {
            // Optionally close the window after printing
            // setTimeout(() => window.close(), 1000);
        });
    </script>
</body>
</html>