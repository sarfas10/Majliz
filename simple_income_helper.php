<?php
// simple_income_helper.php - SIMPLIFIED VERSION (no advanced distribution)

/**
 * Gets grouped income transactions by category for a period
 */
function get_grouped_income_transactions($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT 
            category,
            SUM(amount) as total_amount,
            COUNT(*) as transaction_count,
            GROUP_CONCAT(DISTINCT description SEPARATOR ', ') as descriptions,
            MIN(transaction_date) as first_date,
            MAX(transaction_date) as last_date
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND transaction_date BETWEEN ? AND ?
        GROUP BY category
        ORDER BY category
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $grouped_transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $grouped_transactions[] = [
            'category' => $row['category'],
            'total_amount' => floatval($row['total_amount']),
            'transaction_count' => intval($row['transaction_count']),
            'descriptions' => $row['descriptions'],
            'first_date' => $row['first_date'],
            'last_date' => $row['last_date']
        ];
    }
    $stmt->close();
    
    return $grouped_transactions;
}

/**
 * Gets grouped expense transactions by category for a period
 */
function get_grouped_expense_transactions($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT 
            category,
            SUM(amount) as total_amount,
            COUNT(*) as transaction_count,
            GROUP_CONCAT(DISTINCT description SEPARATOR ', ') as descriptions,
            MIN(transaction_date) as first_date,
            MAX(transaction_date) as last_date
        FROM transactions 
        WHERE user_id = ? AND type = 'EXPENSE' 
        AND transaction_date BETWEEN ? AND ?
        GROUP BY category
        ORDER BY category
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $grouped_transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $grouped_transactions[] = [
            'category' => $row['category'],
            'total_amount' => floatval($row['total_amount']),
            'transaction_count' => intval($row['transaction_count']),
            'descriptions' => $row['descriptions'],
            'first_date' => $row['first_date'],
            'last_date' => $row['last_date']
        ];
    }
    $stmt->close();
    
    return $grouped_transactions;
}

/**
 * Gets total income for period (simple sum)
 */
function get_total_income($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND transaction_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total'] ?? 0);
}

/**
 * Gets total expense for period (simple sum)
 */
function get_total_expense($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total
        FROM transactions 
        WHERE user_id = ? AND type = 'EXPENSE' 
        AND transaction_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total'] ?? 0);
}

/**
 * Gets total for a specific category
 */
function get_category_total($conn, $user_id, $category, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND category = ?
        AND transaction_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("isss", $user_id, $category, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return floatval($row['total'] ?? 0);
}
?>