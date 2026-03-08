<?php
// export_finance_excel.php
// Real XLSX exporter for financial data

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

require_once 'db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    http_response_code(500);
    echo "DB connection error: " . htmlspecialchars($db['error']);
    exit;
}
$conn = $db['conn'];

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo "Missing Composer autoload.";
    exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$mahal_id = (int)($_SESSION['user_id'] ?? 0);

// Get filter parameters from GET request
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$type_filter = $_GET['type_filter'] ?? 'all';
$category_filter = $_GET['category_filter'] ?? 'all';
$payment_mode_filter = $_GET['payment_mode_filter'] ?? 'all';

function safe_prepare_and_execute(mysqli $conn, string $sql, array $bindTypes = [], array $bindValues = []) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new RuntimeException($conn->error);

    if (!empty($bindTypes)) {
        $types = implode('', $bindTypes);
        $refs = [ &$types ];
        foreach ($bindValues as $i => $v) $refs[] = &$bindValues[$i];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

try {
    // Build the query with filters
    $where = ["t.user_id = ?"];
    $types = ["i"];
    $params = [$mahal_id];

    // Date range filter
    if ($date_from && $date_to) {
        $where[] = "t.transaction_date BETWEEN ? AND ?";
        $types[] = "s";
        $types[] = "s";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif ($date_from) {
        $where[] = "t.transaction_date >= ?";
        $types[] = "s";
        $params[] = $date_from;
    } elseif ($date_to) {
        $where[] = "t.transaction_date <= ?";
        $types[] = "s";
        $params[] = $date_to;
    }

    // Type filter
    if ($type_filter !== 'all') {
        $where[] = "t.type = ?";
        $types[] = "s";
        $params[] = $type_filter;
    }

    // Category filter
    if ($category_filter !== 'all') {
        $where[] = "t.category = ?";
        $types[] = "s";
        $params[] = $category_filter;
    }

    // Payment mode filter
    if ($payment_mode_filter !== 'all') {
        $where[] = "t.payment_mode = ?";
        $types[] = "s";
        $params[] = $payment_mode_filter;
    }

    $sql = "SELECT 
                t.id,
                t.transaction_date,
                t.type,
                t.category,
                t.amount,
                t.description,
                t.other_expense_detail,
                t.donor_details,
                t.payment_mode,
                t.receipt_no,
                t.created_at,
                m.head_name as donor_head_name,
                m.member_number as donor_member_number,
                s.name as staff_name
            FROM transactions t
            LEFT JOIN members m ON t.donor_member_id = m.id
            LEFT JOIN staff s ON t.staff_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.transaction_date DESC, t.created_at DESC";

    $transactions = safe_prepare_and_execute($conn, $sql, $types, $params);

    // Debug: Check what data we're getting (remove this in production)
    // error_log("Total transactions: " . count($transactions));
    // if (count($transactions) > 0) {
    //     error_log("First transaction sample: " . print_r($transactions[0], true));
    // }

    // ------------------------------------------
    // EXCEL creation
    // ------------------------------------------
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Financial Transactions');

    // HEADER
    $header = [
        "Receipt No", 
        "Date", 
        "Type", 
        "Category", 
        "Description", 
        "Other Expense Detail", 
        "Amount", 
        "Donor (Person)", 
        "Donor (Head)", 
        "Member Number", 
        "Payment Mode", 
        "Staff", 
        "Created Date"
    ];

    // Write header
    $rowNum = 1;
    foreach ($header as $idx => $h) {
        $coord = Coordinate::stringFromColumnIndex($idx+1) . $rowNum;
        $sheet->setCellValue($coord, $h);
        $sheet->getStyle($coord)->getFont()->setBold(true);
        $sheet->getStyle($coord)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    // Write transaction rows
    $r = 2;
    foreach ($transactions as $transaction) {
        // Debug individual transaction (remove in production)
        // error_log("Processing transaction: " . print_r($transaction, true));
        
        $vals = [
            $transaction['receipt_no'] ?? '',
            $transaction['transaction_date'] ?? '',
            $transaction['type'] ?? '',
            $transaction['category'] ?? '',
            $transaction['description'] ?? '',
            $transaction['other_expense_detail'] ?? '',
            $transaction['amount'] ?? 0,
            // Donor (Person) - using donor_details from transactions table
            $transaction['donor_details'] ?? '',
            // Donor (Head) - from members table join
            $transaction['donor_head_name'] ?? '',
            // Member Number - from members table join
            $transaction['donor_member_number'] ?? '',
            $transaction['payment_mode'] ?? '',
            $transaction['staff_name'] ?? '',
            $transaction['created_at'] ?? ''
        ];

        foreach ($vals as $ci => $val) {
            $coord = Coordinate::stringFromColumnIndex($ci+1) . $r;
            $sheet->setCellValue($coord, $val);
            $sheet->getStyle($coord)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
        $r++;
    }

    // Apply left alignment to all columns and autosize
    foreach (range('A','M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
        // Apply left alignment to entire column
        $sheet->getStyle($col . '1:' . $col . $sheet->getHighestRow())
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    $mahal = preg_replace('/[^a-zA-Z0-9_-]/','_', $_SESSION['mahal_name'] ?? 'Mahal');
    
    // Add filter info to filename
    $filter_info = '';
    if ($date_from || $date_to) {
        $filter_info .= '_' . str_replace('-', '', $date_from) . '_to_' . str_replace('-', '', $date_to);
    }
    if ($type_filter !== 'all') {
        $filter_info .= '_' . $type_filter;
    }
    if ($category_filter !== 'all') {
        $filter_info .= '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $category_filter);
    }
    
    $filename = $mahal . "_Finance_Report" . $filter_info . "_" . date("Y-m-d_His") . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save("php://output");
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Export failed</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}
?>