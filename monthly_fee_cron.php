<?php

// monthly_fee_cron.php (per-Mahal fees, per-member amounts + Sahakari members)
// Adds each member's own monthly_fee to their total_due once per month.
// Handles:
//   - Normal members (members table)
//   - Sahakari members (sahakari_members table)
// Safe to call multiple times; runs once per Mahal per month.

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

/*
// --- OPTIONAL DEBUG (uncomment only if needed) ---
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);
// -------------------------------------------------
*/

// ----------------- CONFIG -----------------
const CRON_SECRET = 'A49kP72qL88rM31vR60tB94nX27sC55y'; // shell-safe secret

// HTTP guard (allow CLI without key)
if (php_sapi_name() !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== CRON_SECRET) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ----------------- DB BOOTSTRAP -----------------
require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    cron_respond(false, 'DB connection failed: ' . $db['error']);
}
/** @var mysqli $conn */
$conn = $db['conn'];
$conn->set_charset('utf8mb4');


// ----------------- LAST DAY OF MONTH CHECK (Hostinger-compatible) -----------------
$BACKFILL_MODE = false; // TEMPORARY — set to false after December backfill

if (!$BACKFILL_MODE) {
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $lastDay = (clone $now)->modify('last day of this month');

    if ($now->format('Y-m-d') !== $lastDay->format('Y-m-d')) {
        cron_respond(true, 'Skipped — not last day (IST-safe)');
    }
}
/**
 * Safely add a column; ignores "Duplicate column name" (1060).
 */
function cron_safe_add_column(mysqli $conn, string $table, string $columnDef): void {
    $sql = "ALTER TABLE `{$table}` ADD COLUMN {$columnDef}";
    try {
        $conn->query($sql);
    } catch (Throwable $e) {
        // Duplicate column name
        if ((int)$e->getCode() !== 1060) {
            throw $e;
        }
    }
}

// ----------------- SCHEMA SAFETY (best-effort) -----------------

// NORMAL MEMBERS
cron_safe_add_column($conn, 'members', "total_due DECIMAL(10,2) NOT NULL DEFAULT 0.00");
cron_safe_add_column($conn, 'members', "monthly_donation_due VARCHAR(20) NOT NULL DEFAULT 'pending'");
cron_safe_add_column($conn, 'members', "monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
cron_safe_add_column($conn, 'members', "status VARCHAR(20) NOT NULL DEFAULT 'active'");
cron_safe_add_column($conn, 'members', "monthly_fee_adv DECIMAL(12,2) NOT NULL DEFAULT 0.00");

// SAHAKARI MEMBERS
cron_safe_add_column($conn, 'sahakari_members', "total_due DECIMAL(10,2) NOT NULL DEFAULT 0.00");
cron_safe_add_column($conn, 'sahakari_members', "monthly_donation_due VARCHAR(20) NOT NULL DEFAULT 'pending'");
cron_safe_add_column($conn, 'sahakari_members', "monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
cron_safe_add_column($conn, 'sahakari_members', "status VARCHAR(20) NOT NULL DEFAULT 'active'");
cron_safe_add_column($conn, 'sahakari_members', "monthly_fee_adv DECIMAL(12,2) NOT NULL DEFAULT 0.00");

// REGISTER (per-mahal default fee if you ever use it)
cron_safe_add_column($conn, 'register', "monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");

// Run log for each mahal+month (no foreign key to avoid engine/type issues)
$conn->query("
CREATE TABLE IF NOT EXISTS monthly_fee_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    run_month DATE NOT NULL,
    applied_amount DECIMAL(12,2) NOT NULL,
    member_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mahal_month (mahal_id, run_month),
    KEY idx_mahal (mahal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Per-member audit log (normal members) – no FKs
$conn->query("
CREATE TABLE IF NOT EXISTS members_monthly_fee_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    member_id INT NOT NULL,
    run_month DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mahal_month (mahal_id, run_month),
    KEY idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Per-member audit log (sahakari members) – no FKs
$conn->query("
CREATE TABLE IF NOT EXISTS sahakari_members_monthly_fee_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    mahal_id INT NOT NULL,
    sahakari_member_id INT NOT NULL,
    run_month DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mahal_month (mahal_id, run_month),
    KEY idx_member (sahakari_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ----------------- RUN PER MAHAL -----------------

// We keep run_month as the 1st day of this month to represent the billed month.
// Script itself only actually runs on the last calendar day (guard above).
$runMonth = (new DateTime('first day of this month'))->format('Y-m-d');


// Fetch all Mahals
$mahals = [];
$res = $conn->query("SELECT id AS mahal_id FROM register");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $mahals[] = ['mahal_id' => (int)$row['mahal_id']];
    }
    $res->free();
}

$results = [];

foreach ($mahals as $m) {
    $mahalId = (int)$m['mahal_id'];

    // Idempotency check per Mahal per Month
    $chk = $conn->prepare("SELECT 1 FROM monthly_fee_runs WHERE mahal_id = ? AND run_month = ?");
    if (!$chk) {
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => 'Prepare failed (idempotency check): ' . $conn->error
        ];
        continue;
    }
    $chk->bind_param("is", $mahalId, $runMonth);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        $results[] = [
            'mahal_id'       => $mahalId,
            'status'         => 'skipped',
            'reason'         => "already applied for $runMonth",
            'applied_amount' => 0.00,
            'members'        => 0
        ];
        continue;
    }
    $chk->close();

    // Count NORMAL members in this Mahal
    $cnt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM members 
        WHERE mahal_id = ? AND monthly_fee > 0 AND status NOT IN ('death','terminate')
    ");
    if (!$cnt) {
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => 'Prepare failed (count members): ' . $conn->error
        ];
        continue;
    }
    $cnt->bind_param("i", $mahalId);
    $cnt->execute();
    $membersCountRes = $cnt->get_result();
    $r = $membersCountRes ? $membersCountRes->fetch_assoc() : ['c' => 0];
    $eligibleMembers = (int)($r['c'] ?? 0);
    $cnt->close();

    // Count SAHAKARI members in this Mahal
    $cntS = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM sahakari_members 
        WHERE mahal_id = ? AND monthly_fee > 0 AND status NOT IN ('death','terminate')
    ");
    if (!$cntS) {
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => 'Prepare failed (count sahakari): ' . $conn->error
        ];
        continue;
    }
    $cntS->bind_param("i", $mahalId);
    $cntS->execute();
    $sCountRes = $cntS->get_result();
    $rS = $sCountRes ? $sCountRes->fetch_assoc() : ['c' => 0];
    $eligibleSahakari = (int)($rS['c'] ?? 0);
    $cntS->close();

    $eligibleCount = $eligibleMembers + $eligibleSahakari;

    // Fetch NORMAL members
    $membersQ = $conn->prepare("
        SELECT id, monthly_fee, monthly_fee_adv
        FROM members
        WHERE mahal_id = ? AND monthly_fee > 0 AND status NOT IN ('death','terminate')
    ");
    if (!$membersQ) {
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => 'Prepare failed (select members): ' . $conn->error
        ];
        continue;
    }
    $membersQ->bind_param("i", $mahalId);
    $membersQ->execute();
    $membersRes = $membersQ->get_result();
    $members = [];
    if ($membersRes) {
        while ($row = $membersRes->fetch_assoc()) {
            $members[] = $row;
        }
    }
    $membersQ->close();

    // Fetch SAHAKARI members
    $sahQ = $conn->prepare("
        SELECT id, monthly_fee, monthly_fee_adv
        FROM sahakari_members
        WHERE mahal_id = ? AND monthly_fee > 0 AND status NOT IN ('death','terminate')
    ");
    if (!$sahQ) {
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => 'Prepare failed (select sahakari): ' . $conn->error
        ];
        continue;
    }
    $sahQ->bind_param("i", $mahalId);
    $sahQ->execute();
    $sahRes = $sahQ->get_result();
    $sahMembers = [];
    if ($sahRes) {
        while ($row = $sahRes->fetch_assoc()) {
            $sahMembers[] = $row;
        }
    }
    $sahQ->close();

    // If no members to process, still record run
    if (count($members) === 0 && count($sahMembers) === 0) {
        $run = $conn->prepare("
            INSERT INTO monthly_fee_runs (mahal_id, run_month, applied_amount, member_count) 
            VALUES (?, ?, ?, ?)
        ");
        if ($run) {
            $zero = 0.00;
            $memberCount = 0;
            $run->bind_param("isdi", $mahalId, $runMonth, $zero, $memberCount);
            $run->execute();
            $run->close();
        }

        $results[] = [
            'mahal_id'           => $mahalId,
            'status'             => 'applied',
            'applied_amount'     => 0.00,
            'members_eligible'   => $eligibleCount,
            'members_with_fee'   => 0,
            'sahakari_eligible'  => $eligibleSahakari,
            'sahakari_with_fee'  => 0
        ];
        continue;
    }

    // Start transaction per Mahal
    $conn->begin_transaction();
    try {
        $totalAppliedToDue = 0.00;
        $affectedMembers   = 0;
        $affectedSahakari  = 0;

        // ---------- NORMAL MEMBERS ----------
        $upd_add_due = $conn->prepare("
            UPDATE members 
            SET total_due = total_due + ?, monthly_fee_adv = ?, monthly_donation_due = ? 
            WHERE id = ?
        ");
        if (!$upd_add_due) {
            throw new Exception("Prepare failed (upd_add_due): " . $conn->error);
        }

        $upd_only_adv = $conn->prepare("
            UPDATE members 
            SET monthly_fee_adv = ?, monthly_donation_due = ? 
            WHERE id = ?
        ");
        if (!$upd_only_adv) {
            throw new Exception("Prepare failed (upd_only_adv): " . $conn->error);
        }

        $log_stmt = $conn->prepare("
            INSERT INTO members_monthly_fee_log (mahal_id, member_id, run_month, amount) 
            VALUES (?, ?, ?, ?)
        ");
        if (!$log_stmt) {
            throw new Exception("Prepare failed (log_stmt): " . $conn->error);
        }

        foreach ($members as $member) {
            $memberId   = (int)$member['id'];
            $monthlyFee = (float)$member['monthly_fee'];
            $adv        = (float)$member['monthly_fee_adv'];

            if ($monthlyFee <= 0.0) {
                continue;
            }

            if ($adv >= $monthlyFee) {
                // Advance fully covers the fee -> deduct from advance, do NOT add to total_due
                $newAdv = $adv - $monthlyFee;
                $newMonthlyDonationDue = 'cleared';

                $upd_only_adv->bind_param("dsi", $newAdv, $newMonthlyDonationDue, $memberId);
                if (!$upd_only_adv->execute()) {
                    throw new Exception("Failed updating member advance for member {$memberId}: " . $upd_only_adv->error);
                }
            } elseif ($adv > 0.0 && $adv < $monthlyFee) {
                // Advance partially covers fee -> zero the advance and add remainder to total_due
                $newAdv = 0.00;
                $toAdd  = $monthlyFee - $adv;
                $newMonthlyDonationDue = 'due';

                $upd_add_due->bind_param("ddsi", $toAdd, $newAdv, $newMonthlyDonationDue, $memberId);
                if (!$upd_add_due->execute()) {
                    throw new Exception("Failed updating partial advance for member {$memberId}: " . $upd_add_due->error);
                }
                $totalAppliedToDue += $toAdd;
            } else {
                // No advance -> add full monthly_fee to total_due
                $newAdv = 0.00;
                $toAdd  = $monthlyFee;
                $newMonthlyDonationDue = 'due';

                $upd_add_due->bind_param("ddsi", $toAdd, $newAdv, $newMonthlyDonationDue, $memberId);
                if (!$upd_add_due->execute()) {
                    throw new Exception("Failed updating due for member {$memberId}: " . $upd_add_due->error);
                }
                $totalAppliedToDue += $toAdd;
            }

            // Per-member audit log (we log the full monthly fee)
            $log_stmt->bind_param("iisd", $mahalId, $memberId, $runMonth, $monthlyFee);
            if (!$log_stmt->execute()) {
                throw new Exception("Failed inserting monthly_fee_log for member {$memberId}: " . $log_stmt->error);
            }

            $affectedMembers++;
        }

        $upd_add_due->close();
        $upd_only_adv->close();
        $log_stmt->close();

        // ---------- SAHAKARI MEMBERS ----------
        $upd_add_due_s = $conn->prepare("
            UPDATE sahakari_members 
            SET total_due = total_due + ?, monthly_fee_adv = ?, monthly_donation_due = ? 
            WHERE id = ?
        ");
        if (!$upd_add_due_s) {
            throw new Exception("Prepare failed (upd_add_due_s): " . $conn->error);
        }

        $upd_only_adv_s = $conn->prepare("
            UPDATE sahakari_members 
            SET monthly_fee_adv = ?, monthly_donation_due = ? 
            WHERE id = ?
        ");
        if (!$upd_only_adv_s) {
            throw new Exception("Prepare failed (upd_only_adv_s): " . $conn->error);
        }

        $log_stmt_s = $conn->prepare("
            INSERT INTO sahakari_members_monthly_fee_log (mahal_id, sahakari_member_id, run_month, amount) 
            VALUES (?, ?, ?, ?)
        ");
        if (!$log_stmt_s) {
            throw new Exception("Prepare failed (log_stmt_s): " . $conn->error);
        }

        foreach ($sahMembers as $sMember) {
            $sId        = (int)$sMember['id'];
            $monthlyFee = (float)$sMember['monthly_fee'];
            $adv        = (float)$sMember['monthly_fee_adv'];

            if ($monthlyFee <= 0.0) {
                continue;
            }

            if ($adv >= $monthlyFee) {
                // Advance fully covers the fee
                $newAdv = $adv - $monthlyFee;
                $newMonthlyDonationDue = 'cleared';

                $upd_only_adv_s->bind_param("dsi", $newAdv, $newMonthlyDonationDue, $sId);
                if (!$upd_only_adv_s->execute()) {
                    throw new Exception("Failed updating sahakari advance for member {$sId}: " . $upd_only_adv_s->error);
                }
            } elseif ($adv > 0.0 && $adv < $monthlyFee) {
                // Partial coverage by advance
                $newAdv = 0.00;
                $toAdd  = $monthlyFee - $adv;
                $newMonthlyDonationDue = 'due';

                $upd_add_due_s->bind_param("ddsi", $toAdd, $newAdv, $newMonthlyDonationDue, $sId);
                if (!$upd_add_due_s->execute()) {
                    throw new Exception("Failed updating sahakari partial advance for member {$sId}: " . $upd_add_due_s->error);
                }
                $totalAppliedToDue += $toAdd;
            } else {
                // No advance -> full fee to total_due
                $newAdv = 0.00;
                $toAdd  = $monthlyFee;
                $newMonthlyDonationDue = 'due';

                $upd_add_due_s->bind_param("ddsi", $toAdd, $newAdv, $newMonthlyDonationDue, $sId);
                if (!$upd_add_due_s->execute()) {
                    throw new Exception("Failed updating sahakari due for member {$sId}: " . $upd_add_due_s->error);
                }
                $totalAppliedToDue += $toAdd;
            }

            // Per-sahakari-member audit log
            $log_stmt_s->bind_param("iisd", $mahalId, $sId, $runMonth, $monthlyFee);
            if (!$log_stmt_s->execute()) {
                throw new Exception("Failed inserting sahakari_monthly_fee_log for member {$sId}: " . $log_stmt_s->error);
            }

            $affectedSahakari++;
        }

        $upd_add_due_s->close();
        $upd_only_adv_s->close();
        $log_stmt_s->close();

        // Record the run
        $run = $conn->prepare("
            INSERT INTO monthly_fee_runs (mahal_id, run_month, applied_amount, member_count) 
            VALUES (?, ?, ?, ?)
        ");
        if (!$run) {
            throw new Exception("Prepare failed (run insert): " . $conn->error);
        }

        $memberCount = $affectedMembers + $affectedSahakari;
        $run->bind_param("isdi", $mahalId, $runMonth, $totalAppliedToDue, $memberCount);
        if (!$run->execute()) {
            throw new Exception("Failed inserting monthly_fee_runs: " . $run->error);
        }
        $run->close();

        $conn->commit();

        $results[] = [
            'mahal_id'           => $mahalId,
            'status'             => 'applied',
            'run_month'          => $runMonth,
            'applied_amount'     => round($totalAppliedToDue, 2),
            'members_eligible'   => $eligibleMembers,
            'members_with_fee'   => $affectedMembers,
            'sahakari_eligible'  => $eligibleSahakari,
            'sahakari_with_fee'  => $affectedSahakari
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        $results[] = [
            'mahal_id' => $mahalId,
            'status'   => 'error',
            'message'  => $e->getMessage()
        ];
    }
}

cron_respond(true, ['run_month' => $runMonth, 'results' => $results]);

// ----------------- RESPONDER -----------------
function cron_respond(bool $ok, $payload): void {
    $out = ['success' => $ok, 'data' => $payload];
    if (php_sapi_name() === 'cli') {
        echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($out);
    }
    exit;
}
