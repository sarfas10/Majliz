<?php
// print_regular_report.php - SIMPLIFIED VERSION (no advanced distribution)
require_once __DIR__ . '/session_bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/simple_income_helper.php'; // Changed from advanced_income_helper.php

$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$openingBalance = isset($_GET['opening']) ? floatval($_GET['opening']) : 0;

if (empty($dateFrom) || empty($dateTo)) {
    die("Date range is required");
}

$user_id = (int) $_SESSION['user_id'];

$db_result = get_db_connection();
if (isset($db_result['error'])) {
    die("Database connection failed");
}
$conn = $db_result['conn'];

// Get mahal details
$stmt = $conn->prepare("SELECT name, address, registration_no FROM register WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mahal_result = $stmt->get_result();
$mahal = $mahal_result->fetch_assoc();
$stmt->close();

// Get GROUPED income transactions (ALL income)
$grouped_income = get_grouped_income_transactions($conn, $user_id, $dateFrom, $dateTo);

// Get GROUPED expense transactions
$grouped_expense = get_grouped_expense_transactions($conn, $user_id, $dateFrom, $dateTo);

// Calculate totals
$total_income = get_total_income($conn, $user_id, $dateFrom, $dateTo);
$total_expense = get_total_expense($conn, $user_id, $dateFrom, $dateTo);

$closing_balance = $openingBalance + $total_income - $total_expense;

// Get balance by payment mode within the date range
$balance_by_mode = [];
$stmt_mode = $conn->prepare("
    SELECT payment_mode,
        SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) -
        SUM(CASE WHEN type = 'EXPENSE' THEN amount ELSE 0 END) AS net_balance
    FROM transactions
    WHERE user_id = ? AND transaction_date BETWEEN ? AND ?
    GROUP BY payment_mode
    ORDER BY payment_mode
");
$stmt_mode->bind_param("iss", $user_id, $dateFrom, $dateTo);
$stmt_mode->execute();
$mode_result = $stmt_mode->get_result();
while ($mode_row = $mode_result->fetch_assoc()) {
    $balance_by_mode[] = $mode_row;
}
$stmt_mode->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Report - <?php echo htmlspecialchars($mahal['name']); ?></title>
    <style>
        /* -------- A4 PAGE -------- */
        @page {
            size: A4 portrait;
            margin: 12mm 15mm;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
                width: 100%;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }

            .report-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 10pt;
            background: #ffffff;
            color: #000000;
            line-height: 1.3;
            padding: 0;
            margin: 0;
        }

        .report-container {
            width: 100%;
            padding: 0;
            margin: 0 auto;
        }

        .print-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: bold;
            background: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            z-index: 1000;
            border-radius: 4px;
        }

        .print-btn:hover {
            background: #45a049;
        }

        /* -------- HEADER -------- */
        .header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #000;
        }

        .header h1 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }

        .header .subline {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .header .reg {
            font-size: 11px;
            font-weight: bold;
        }

        .period {
            text-align: center;
            margin: 10px 0 15px 0;
            font-weight: bold;
            font-size: 13px;
            padding: 6px;
            border: 2px solid #000;
            background: #f0f0f0;
        }

        /* -------- MAIN TABLE - PERFECTLY EQUAL 50-50 -------- */
        table.financial-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10pt;
        }

        table.financial-table th,
        table.financial-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* PERFECTLY EQUAL COLUMNS */
        table.financial-table col:nth-child(1) {
            width: 37.5%;
        }

        table.financial-table col:nth-child(2) {
            width: 12.5%;
        }

        table.financial-table col:nth-child(3) {
            width: 37.5%;
        }

        table.financial-table col:nth-child(4) {
            width: 12.5%;
        }

        .section-header th {
            font-size: 13px;
            text-align: center;
            font-weight: bold;
            background: #d0d0d0;
            padding: 8px;
        }

        .column-header th {
            font-size: 11px;
            background: #e8e8e8;
            font-weight: bold;
            padding: 6px 8px;
            text-align: center;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-left {
            text-align: left !important;
        }

        .total-row td {
            font-weight: bold;
            background: #e0e0e0;
            padding: 7px 8px;
            font-size: 11pt;
        }

        /* -------- SUMMARY PANEL -------- */
        .summary-box {
            margin-top: 15px;
            border: 2px solid #000;
            padding: 10px 12px;
            font-size: 11px;
            background: #f5f5f5;
        }

        .summary-title {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 8px;
            text-align: center;
            text-decoration: underline;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            padding: 2px 0;
        }

        .summary-label {
            font-weight: bold;
        }

        .summary-value {
            font-weight: bold;
        }

        .summary-footer {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 2px solid #000;
            font-weight: bold;
            font-size: 13px;
            text-align: right;
        }

        /* -------- FOOTER -------- */
        .footer {
            margin-top: 15px;
            padding-top: 8px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ccc;
        }

        /* -------- PRINT OPTIMIZATION -------- */
        @media print {
            .financial-table {
                page-break-inside: auto;
            }

            .financial-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            .summary-box {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ PRINT REPORT</button>

    <div class="report-container">
        <!-- HEADER -->
        <div class="header">
            <h1><?php echo htmlspecialchars($mahal['name']); ?></h1>
            <div class="subline"><?php echo htmlspecialchars($mahal['address']); ?></div>
            <div class="reg">Registration No: <?php echo htmlspecialchars($mahal['registration_no']); ?></div>
        </div>

        <div class="period">
            Balance Sheet Period:
            <?php echo date('d-m-Y', strtotime($dateFrom)); ?>
            to
            <?php echo date('d-m-Y', strtotime($dateTo)); ?>
        </div>

        <!-- MAIN TABLE -->
        <?php
        // Build display arrays
        $income_display = [];
        $income_display[] = ['label' => 'OPENING BALANCE', 'amount' => $openingBalance];

        // Add grouped income transactions (ALL income, including MONTHLY FEE)
        foreach ($grouped_income as $income) {
            $label = strtoupper($income['category'] ?: 'OTHER INCOME');

            $income_display[] = [
                'label' => $label,
                'amount' => $income['total_amount']
            ];
        }

        $expense_display = [];
        if (empty($grouped_expense)) {
            $expense_display[] = ['label' => 'NO EXPENSES', 'amount' => 0];
        } else {
            foreach ($grouped_expense as $expense) {
                $label = strtoupper($expense['category'] ?: 'OTHER EXPENSE');

                $expense_display[] = [
                    'label' => $label,
                    'amount' => $expense['total_amount']
                ];
            }
        }

        // Add CLOSING BALANCE row (in expense column)
        $expense_display[] = ['label' => 'CLOSING BALANCE', 'amount' => $closing_balance];

        // Calculate TOTAL INCOME and TOTAL EXPENSE
        $total_income_display = $openingBalance + $total_income;
        $total_expense_display = $total_expense + $closing_balance;

        // Calculate max rows for proper alignment (excluding the total row we'll add separately)
        $income_rows = count($income_display);
        $expense_rows = count($expense_display);
        $maxRows = max($income_rows, $expense_rows);
        ?>

        <table class="financial-table">
            <colgroup>
                <col style="width: 37.5%;">
                <col style="width: 12.5%;">
                <col style="width: 37.5%;">
                <col style="width: 12.5%;">
            </colgroup>
            <thead>
                <tr class="section-header">
                    <th colspan="2">INCOME</th>
                    <th colspan="2">EXPENSE</th>
                </tr>
                <tr class="column-header">
                    <th>PARTICULARS</th>
                    <th>AMOUNT</th>
                    <th>PARTICULARS</th>
                    <th>AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // First, display all regular rows (not bold)
                for ($i = 0; $i < $maxRows; $i++):
                    $inc = $income_display[$i] ?? null;
                    $exp = $expense_display[$i] ?? null;
                    ?>
                    <tr>
                        <td class="text-left">
                            <?php echo $inc ? htmlspecialchars($inc['label']) : '&nbsp;'; ?>
                        </td>
                        <td class="text-right">
                            <?php
                            if ($inc) {
                                echo number_format((float) $inc['amount'], 2);
                            } else {
                                echo '&nbsp;';
                            }
                            ?>
                        </td>
                        <td class="text-left">
                            <?php
                            if ($exp) {
                                echo htmlspecialchars($exp['label']);
                            } else {
                                echo '&nbsp;';
                            }
                            ?>
                        </td>
                        <td class="text-right">
                            <?php
                            if ($exp) {
                                echo number_format((float) $exp['amount'], 2);
                            } else {
                                echo '&nbsp;';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endfor; ?>

                <!-- TOTAL ROW - Only TOTAL INCOME and TOTAL EXPENSE are bold -->
                <tr class="total-row">
                    <td class="text-left">
                        <strong>TOTAL INCOME</strong>
                    </td>
                    <td class="text-right">
                        <strong><?php echo number_format($total_income_display, 2); ?></strong>
                    </td>
                    <td class="text-left">
                        <strong>TOTAL EXPENSE</strong>
                    </td>
                    <td class="text-right">
                        <strong><?php echo number_format($total_expense_display, 2); ?></strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- SUMMARY BOX -->
        <div class="summary-box">
            <div class="summary-title">Summary</div>

            <div class="summary-row">
                <span class="summary-label">Opening Balance:</span>
                <span class="summary-value">₹ <?php echo number_format($openingBalance, 2); ?></span>
            </div>

            <div class="summary-row">
                <span class="summary-label">Total Income (Period):</span>
                <span class="summary-value">₹ <?php echo number_format($total_income, 2); ?></span>
            </div>

            <div class="summary-row">
                <span class="summary-label">Total Expense (Period):</span>
                <span class="summary-value">₹ <?php echo number_format($total_expense, 2); ?></span>
            </div>

            <div class="summary-footer">
                Closing Balance: ₹ <?php echo number_format($closing_balance, 2); ?>            </div>
        </div>

    <!-- BALANCE BY PAYMENT MODE -->
    <div class="summary-box" style="margin-top: 10px; background: #e8f5e9;">
        <div class="summary-title">Balance by Payment Mode</div>
        <?php if (empty($balance_by_mode)): ?>
            <div class="summary-row">
                <span class="summary-label">No transactions found in this period.</span>
            </div>
        <?php else: ?>
            <?php foreach ($balance_by_mode as $mode_entry): ?>
                <div class="summary-row">
                    <span class="summary-label"><?php echo htmlspecialchars($mode_entry['payment_mode']); ?> Balance:</span>
                    <span class="summary-value">&#8377; <?php echo number_format((float)$mode_entry['net_balance'], 2); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        Generated on <?php echo date('d-m-Y, h:i A'); ?> | This is a computer-generated report
    </div>
</div>

<script>
    window.onload = function () {
        // Auto print removed - use button instead
    };
</script>
</body>
</html>
