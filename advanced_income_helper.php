
<?php
// advanced_income_helper.php - UPDATED VERSION for opening balance

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
 * Gets all income transactions (detailed, not grouped) - for backup/reference
 */
function get_all_income_transactions($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT id, amount, transaction_date, category, description, donor_member_id
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND transaction_date BETWEEN ? AND ?
        ORDER BY category, transaction_date
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $transactions[] = [
            'id' => $row['id'],
            'amount' => floatval($row['amount']),
            'transaction_date' => $row['transaction_date'],
            'category' => $row['category'],
            'description' => $row['description'],
            'donor_member_id' => $row['donor_member_id']
        ];
    }
    $stmt->close();
    
    return $transactions;
}

/**
 * Gets all expense transactions (detailed, not grouped) - for backup/reference
 */
function get_all_expense_transactions($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT id, amount, transaction_date, category, description
        FROM transactions 
        WHERE user_id = ? AND type = 'EXPENSE' 
        AND transaction_date BETWEEN ? AND ?
        ORDER BY category, transaction_date
    ");
    $stmt->bind_param("iss", $user_id, $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $transactions[] = [
            'id' => $row['id'],
            'amount' => floatval($row['amount']),
            'transaction_date' => $row['transaction_date'],
            'category' => $row['category'],
            'description' => $row['description']
        ];
    }
    $stmt->close();
    
    return $transactions;
}

/**
 * Distributes advanced monthly fee payments across months
 * Returns the amount that should be recognized as income for the given period
 */
function distribute_advanced_income($conn, $user_id, $dateFrom, $dateTo) {
    // Get all advanced monthly income transactions made BEFORE or DURING the report period
    $stmt = $conn->prepare("
        SELECT id, amount, transaction_date, description, donor_member_id 
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' AND category = 'MONTHLY FEE(ADVANCE)'
        AND transaction_date <= ?
    ");
    $stmt->bind_param("is", $user_id, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $advanced_transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $advanced_transactions[] = $row;
    }
    $stmt->close();

    $distributed_amount = 0;
    
    foreach ($advanced_transactions as $transaction) {
        $advance_date = $transaction['transaction_date'];
        $advance_amount = floatval($transaction['amount']);
        $member_id = $transaction['donor_member_id'];
        
        if (!$member_id) {
            // If no member ID, distribute immediately in the month of advance
            if ($advance_date >= $dateFrom && $advance_date <= $dateTo) {
                $distributed_amount += $advance_amount;
            }
            continue;
        }
        
        // Get member's monthly fee to calculate number of months
        $member_stmt = $conn->prepare("SELECT monthly_fee FROM members WHERE id = ?");
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member_data = $member_result->fetch_assoc();
        $member_stmt->close();
        
        $monthly_fee = floatval($member_data['monthly_fee'] ?? 0);
        
        if ($monthly_fee <= 0) {
            $months_count = 1;
        } else {
            $months_count = floor($advance_amount / $monthly_fee);
            if ($months_count < 1) $months_count = 1;
        }
        
        $monthly_amount = $advance_amount / $months_count;
        
        // Determine starting month for distribution
        $advance_day = date('d', strtotime($advance_date));
        $current_month = date('Y-m-01', strtotime($advance_date));
        
        // If advance was made after 15th, start from next month
        if ($advance_day > 15) {
            $current_month = date('Y-m-01', strtotime('+1 month', strtotime($advance_date)));
        }
        
        // Distribute across months
        for ($i = 0; $i < $months_count; $i++) {
            $month_start = date('Y-m-01', strtotime("+$i months", strtotime($current_month)));
            
            // Check if this month's distribution (1st day) falls within report period
            if ($month_start >= $dateFrom && $month_start <= $dateTo) {
                $distributed_amount += $monthly_amount;
            }
        }
    }
    
    return $distributed_amount;
}

/**
 * Gets detailed distribution information for reporting
 */
function get_advanced_income_distribution_details($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT t.id, t.amount, t.transaction_date, t.description, t.donor_member_id, 
               m.head_name, m.monthly_fee
        FROM transactions t
        LEFT JOIN members m ON t.donor_member_id = m.id
        WHERE t.user_id = ? AND t.type = 'INCOME' AND t.category = 'MONTHLY FEE(ADVANCE)'
        AND t.transaction_date <= ?
    ");
    $stmt->bind_param("is", $user_id, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution_details = [];
    
    while ($row = $result->fetch_assoc()) {
        $advance_amount = floatval($row['amount']);
        $monthly_fee = floatval($row['monthly_fee'] ?? 0);
        $member_name = $row['head_name'] ?? 'Unknown Member';
        
        if ($monthly_fee <= 0) {
            $months_count = 1;
        } else {
            $months_count = floor($advance_amount / $monthly_fee);
            if ($months_count < 1) $months_count = 1;
        }
        
        $monthly_amount = $advance_amount / $months_count;
        $distributed_for_period = 0;
        $covered_months = [];
        
        // Determine starting month for distribution
        $advance_day = date('d', strtotime($row['transaction_date']));
        $current_month = date('Y-m-01', strtotime($row['transaction_date']));
        
        if ($advance_day > 15) {
            $current_month = date('Y-m-01', strtotime('+1 month', strtotime($row['transaction_date'])));
        }
        
        for ($i = 0; $i < $months_count; $i++) {
            $month_start = date('Y-m-01', strtotime("+$i months", strtotime($current_month)));
            $month_name = date('M Y', strtotime($month_start));
            
            if ($month_start >= $dateFrom && $month_start <= $dateTo) {
                $distributed_for_period += $monthly_amount;
                $covered_months[] = [
                    'month' => $month_name,
                    'distribution_date' => $month_start,
                    'amount' => $monthly_amount
                ];
            }
        }
        
        if ($distributed_for_period > 0) {
            $distribution_details[] = [
                'member_name' => $member_name,
                'advance_amount' => $advance_amount,
                'monthly_fee' => $monthly_fee,
                'months_count' => $months_count,
                'monthly_amount' => $monthly_amount,
                'distributed_amount' => $distributed_for_period,
                'covered_months' => $covered_months,
                'advance_date' => $row['transaction_date']
            ];
        }
    }
    $stmt->close();
    
    return $distribution_details;
}

/**
 * Gets regular monthly fee payments (not from advance)
 */
function get_regular_monthly_fee_payments($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND category = 'MONTHLY FEE'
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
 * Gets advance payments made during the period (actual cash received)
 * These should NOT be counted as income yet - they will be distributed
 */
function get_advance_payments_during_period($conn, $user_id, $dateFrom, $dateTo) {
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' 
        AND category = 'MONTHLY FEE(ADVANCE)'
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
 * Calculate how much of advance payments have been distributed up to a given date
 * UPDATED VERSION for opening balance calculation
 */
function get_distributed_advance_up_to_date($conn, $user_id, $upToDate) {
    // Get all advance payments made up to this date
    $stmt = $conn->prepare("
        SELECT id, amount, transaction_date, donor_member_id, description 
        FROM transactions 
        WHERE user_id = ? AND type = 'INCOME' AND category = 'MONTHLY FEE(ADVANCE)'
        AND transaction_date <= ?
    ");
    $stmt->bind_param("is", $user_id, $upToDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $advanced_transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $advanced_transactions[] = $row;
    }
    $stmt->close();

    $total_distributed = 0;
    
    foreach ($advanced_transactions as $transaction) {
        $advance_date = $transaction['transaction_date'];
        $advance_amount = floatval($transaction['amount']);
        $member_id = $transaction['donor_member_id'];
        
        if (!$member_id) {
            // If no member ID, distribute immediately in the month of advance
            // Check if advance date is on or before upToDate
            if ($advance_date <= $upToDate) {
                $total_distributed += $advance_amount;
            }
            continue;
        }
        
        // Get member's monthly fee
        $member_stmt = $conn->prepare("SELECT monthly_fee FROM members WHERE id = ?");
        $member_stmt->bind_param("i", $member_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member_data = $member_result->fetch_assoc();
        $member_stmt->close();
        
        $monthly_fee = floatval($member_data['monthly_fee'] ?? 0);
        
        // Calculate number of months covered by this advance
        if ($monthly_fee <= 0) {
            // No monthly fee set - distribute full amount in current month
            $months_count = 1;
        } else {
            $months_count = floor($advance_amount / $monthly_fee);
            if ($months_count < 1) $months_count = 1;
        }
        
        $monthly_amount = $advance_amount / $months_count;
        
        // Determine starting month for distribution
        $advance_day = date('d', strtotime($advance_date));
        $current_month = date('Y-m-01', strtotime($advance_date));
        
        // If advance was made after 15th, start distribution from next month
        if ($advance_day > 15) {
            $current_month = date('Y-m-01', strtotime('+1 month', strtotime($advance_date)));
        }
        
        // Count how many months have been distributed up to the given date
        $distributed_months = 0;
        for ($i = 0; $i < $months_count; $i++) {
            $month_start = date('Y-m-01', strtotime("+$i months", strtotime($current_month)));
            
            // If this distribution date is on or before the upToDate, count it
            if ($month_start <= $upToDate) {
                $distributed_months++;
            }
        }
        
        // Add distributed amount
        $total_distributed += ($distributed_months * $monthly_amount);
    }
    
    return $total_distributed;
}
?>
