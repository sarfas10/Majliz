<?php
// get_opening_balance.php - FIXED VERSION with correct closing balance logic
require_once __DIR__ . '/session_bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/advanced_income_helper.php';

$date = $_GET['date'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if (empty($date)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Date is required']);
    exit();
}

try {
    $db_result = get_db_connection();
    if (isset($db_result['error'])) {
        throw new Exception("Database connection failed");
    }
    $conn = $db_result['conn'];

    // Get preceding day for opening balance calculation
    // Opening balance for a date = Closing balance of the previous day
    $preceding_day = date('Y-m-d', strtotime($date . ' -1 day'));
    
    // 1. Calculate ALL income and expenses up to preceding day
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE 
                WHEN type = 'INCOME' 
                THEN amount 
                ELSE 0 
            END) as total_income_all,
            SUM(CASE 
                WHEN type = 'EXPENSE' 
                THEN amount 
                ELSE 0 
            END) as total_expense_all
        FROM transactions 
        WHERE user_id = ? AND transaction_date <= ?
    ");
    $stmt->bind_param("is", $user_id, $preceding_day);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    $total_income_all = floatval($data['total_income_all'] ?? 0);
    $total_expense_all = floatval($data['total_expense_all'] ?? 0);
    
    // 2. Get total advance payments received up to preceding day
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total_advance_payments
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND category = 'MONTHLY FEE(ADVANCE)'
        AND transaction_date <= ?
    ");
    $stmt->bind_param("is", $user_id, $preceding_day);
    $stmt->execute();
    $result = $stmt->get_result();
    $advance_data = $result->fetch_assoc();
    $stmt->close();
    
    $total_advance_payments = floatval($advance_data['total_advance_payments'] ?? 0);
    
    // 3. Calculate how much of those advances have been distributed (recognized as income) up to preceding day
    $distributed_advance_income = get_distributed_advance_up_to_date($conn, $user_id, $preceding_day);
    
    // CORRECT OPENING BALANCE CALCULATION:
    // Opening Balance = (Non-advance Income + Distributed Advance Income) - Expenses
    
    // Calculate non-advance income
    $total_non_advance_income = $total_income_all - $total_advance_payments;
    
    // Opening Balance = (Non-advance Income + Distributed Advance Income) - Expenses
    $opening_balance = ($total_non_advance_income + $distributed_advance_income) - $total_expense_all;
    
    // Calculate remaining advance balance (liability)
    $remaining_advance = $total_advance_payments - $distributed_advance_income;

    $conn->close();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'opening_balance' => $opening_balance,
        'date' => $date,
        'preceding_day' => $preceding_day,
        
        // Detailed breakdown
        'total_income_all' => $total_income_all,
        'total_expense_all' => $total_expense_all,
        'total_advance_payments' => $total_advance_payments,
        'distributed_advance_income' => $distributed_advance_income,
        'remaining_advance' => $remaining_advance,
        
        // For debugging
        'total_non_advance_income' => $total_non_advance_income,
        'calculation_formula' => '($total_non_advance_income + $distributed_advance_income) - $total_expense_all'
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>