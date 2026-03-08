<?php
// member_cert_requests.php
declare(strict_types=1);

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

/* --- session data --- */
$memberSess = $_SESSION['member'];

// Household head id
$household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
    ? (int)$memberSess['parent_member_id']
    : (int)$memberSess['member_id'];

require_once __DIR__ . '/db_connection.php';

$requests = [];
try {
    $db = get_db_connection();
    if (isset($db['error'])) throw new Exception($db['error']);
    /** @var mysqli $conn */
    $conn = $db['conn'];

    $createTableSql = "
        CREATE TABLE IF NOT EXISTS cert_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            certificate_type VARCHAR(50) NOT NULL,
            details_json LONGTEXT NULL,
            groom_photo LONGBLOB NULL,
            bride_photo LONGBLOB NULL,
            notes TEXT NULL,
            status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
            approved_by INT UNSIGNED NULL,
            output_file VARCHAR(255) NULL,
            output_blob LONGBLOB NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_member (member_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $conn->query($createTableSql);

    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'groom_photo'");
    if ($res && $res->num_rows === 0) $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'bride_photo'");
    if ($res && $res->num_rows === 0) $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'output_blob'");
    if ($res && $res->num_rows === 0) $conn->query("ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file");

    $sql = "
        SELECT
            id,
            member_id,
            certificate_type,
            details_json,
            notes,
            status,
            output_file,
            created_at,
            updated_at
        FROM cert_requests
        WHERE member_id = ?
        ORDER BY (status = 'pending') DESC, created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $household_member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $requests[] = $row;
    $stmt->close();
    $conn->close();
} catch (Throwable $e) {
    error_log("member_cert_requests error: " . $e->getMessage());
}

function prettyCertType(string $type): string {
    $t = strtolower($type);
    if ($t === 'marriage')    return 'Marriage Certificate';
    if ($t === 'caste')       return 'Caste Certificate';
    if ($t === 'termination') return 'Membership Termination Certificate';
    if ($t === 'marriage_consent_letter') return 'Marriage Consent Letter';
    return ucfirst($t);
}
function prettyStatus(string $status): string {
    return ucfirst(strtolower($status));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My Certificate Requests</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;--secondary:#64748b;--success:#10b981;--success-light:#d1fae5;--warning:#f59e0b;--warning-light:#fef3c7;--danger:#ef4444;--danger-light:#fee2e2;--info:#06b6d4;--light:#f8fafc;--dark:#1e293b;--border:#e2e8f0;--border-light:#f1f5f9;--text-primary:#1e293b;--text-secondary:#64748b;--text-light:#94a3b8;--shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);--shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05)}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f9fafb;color:var(--text-primary);line-height:1.6;font-size:14px}
.header{background:#fff;border-bottom:1px solid var(--border);padding:1.25rem 2rem;position:sticky;top:0;z-index:10}
.header-content{display:flex;justify-content:space-between;align-items:center;max-width:1100px;margin:0 auto}
.breadcrumb{font-size:.875rem;color:var(--text-secondary);display:flex;align-items:center;gap:.5rem}
.breadcrumb a{color:var(--primary);text-decoration:none}
.breadcrumb a:hover{text-decoration:underline}
.btn{padding:.6rem 1rem;border-radius:10px;border:1px solid var(--border);background:#fff;cursor:pointer;font-weight:600;display:inline-flex;gap:.4rem;align-items:center;text-decoration:none;color:var(--text-primary);font-size:.85rem}
.btn:hover{background:#f8fafc;box-shadow:var(--shadow-sm)}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark);box-shadow:var(--shadow-md)}
.main{max-width:1100px;margin:0 auto;padding:1.25rem}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap}
.page-title{font-size:1.25rem;font-weight:800;color:var(--dark);display:flex;align-items:center;gap:.6rem}
.page-title i{color:var(--primary)}
.badge-pill{display:inline-flex;align-items:center;gap:.4rem;background:#e5edff;color:#1d4ed8;border-radius:999px;padding:.25rem .7rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);margin-bottom:1.25rem}
.card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap}
.card-title{font-weight:700;display:flex;gap:.5rem;align-items:center;font-size:.95rem}
.card-title i{color:var(--primary)}
.card-body{padding:1.1rem 1.25rem}
.info-text{font-size:.85rem;color:var(--text-secondary);margin-bottom:.6rem}
.table-container{width:100%;overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:760px}
thead th{background:#f3f4f6;padding:.7rem .8rem;text-align:left;font-size:.75rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border)}
tbody td{padding:.7rem .8rem;border-bottom:1px solid var(--border-light);font-size:.85rem;vertical-align:top;background:#fff}
tbody tr:nth-child(even) td{background:#f9fafb}
.status-pill{display:inline-flex;align-items:center;padding:.2rem .6rem;border-radius:999px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.status-pending{background:#fef3c7;color:#92400e}
.status-approved{background:#dcfce7;color:#166534}
.status-rejected{background:#fee2e2;color:#991b1b}
.status-completed{background:#e0f2fe;color:#075985}
.type-pill{display:inline-flex;align-items:center;padding:.2rem .6rem;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#eef2ff;color:#4338ca}
.detail-muted{color:var(--text-light);font-size:.8rem}
.notes{white-space:pre-wrap;font-size:.8rem;color:var(--text-secondary)}
.empty{padding:1.5rem 1rem;text-align:center;color:var(--text-secondary);font-size:.9rem}
.chip-row{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.25rem;font-size:.78rem;color:var(--text-secondary)}
.chip{padding:.15rem .45rem;border-radius:999px;background:#f3f4f6}
.output-link{font-size:.78rem;color:var(--primary);text-decoration:none;border:1px solid var(--primary-light);padding:.2rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
.output-link:hover{background:var(--primary-light)}
.output-link-disabled{
    font-size:.78rem;
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    padding:.2rem .55rem;
    border-radius:999px;
    border:1px dashed var(--primary-light);
    opacity:.6;
    cursor:not-allowed;
    pointer-events:none;
}
@media (max-width:768px){.header{padding:1rem 1.1rem}.main{padding:1rem}thead th,tbody td{padding:.6rem .6rem}}
</style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="breadcrumb">
                <a href="member_dashboard.php"><i class="fas fa-house-user"></i> Dashboard</a>
                <span>/</span>
                <span>My Certificate Requests</span>
            </div>
            <div>
                <a href="member_dashboard.php" class="btn">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>

    <div class="main">
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-file-signature"></i>
                <span>My Certificate Requests</span>
            </div>
            <div class="badge-pill">
                <i class="fas fa-list-ul"></i>
                <span><?php echo count($requests); ?> total</span>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Requests Overview</span>
                </div>
                <span class="info-text">Track the status of your certificate requests.</span>
            </div>
            <div class="card-body">
                <?php if (!empty($requests)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                    <th>Output</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $r): ?>
                                <?php
                                    $type   = strtolower((string)$r['certificate_type']);
                                    $status = strtolower((string)$r['status']);
                                    $details = [];
                                    if (!empty($r['details_json'])) {
                                        $decoded = json_decode($r['details_json'], true);
                                        if (is_array($decoded)) $details = $decoded;
                                    }

                                    $summary = ''; 
                                    $chips = [];

                                    if ($type === 'marriage') {
                                        $groom = $details['groom_name'] ?? '';
                                        $bride = $details['bride_name'] ?? '';
                                        if ($groom !== '' || $bride !== '') {
                                            $summary = trim(($groom ?: 'Groom') . ' & ' . ($bride ?: 'Bride'));
                                        }
                                        if (!empty($details['marriage_date'])) $chips[] = 'Date: ' . htmlspecialchars($details['marriage_date']);
                                        if (!empty($details['reg_number'])) $chips[] = 'Reg No: ' . htmlspecialchars($details['reg_number']);
                                        if ($summary === '') $summary = 'Marriage certificate request';
                                    } elseif ($type === 'caste') {
                                        if (!empty($details['applicant_name'])) $summary = htmlspecialchars($details['applicant_name']);
                                        else $summary = 'Caste certificate request';
                                        if (!empty($details['caste_name'])) $chips[] = 'Caste: ' . htmlspecialchars($details['caste_name']);
                                        if (!empty($details['village_name'])) $chips[] = htmlspecialchars($details['village_name']);
                                    } elseif ($type === 'termination') {
                                        $summary = 'Membership termination request';
                                        if (!empty($details['termination_date'])) {
                                            $chips[] = 'Termination date: ' . htmlspecialchars($details['termination_date']);
                                        }
                                        if (!empty($details['reason_short'])) {
                                            $chips[] = htmlspecialchars($details['reason_short']);
                                        }
                                    } elseif ($type === 'marriage_consent_letter') {
                                        $groom = $details['groom_name'] ?? '';
                                        $bride = $details['bride_name'] ?? '';
                                        if ($groom !== '' || $bride !== '') {
                                            $summary = trim(($groom ?: 'Groom') . ' & ' . ($bride ?: 'Bride'));
                                        } else {
                                            $summary = 'Marriage consent letter request';
                                        }
                                        if (!empty($details['marriage_date'])) $chips[] = 'Date: ' . htmlspecialchars($details['marriage_date']);
                                        if (!empty($details['reg_number'])) $chips[] = 'Reg No: ' . htmlspecialchars($details['reg_number']);
                                    } else {
                                        $summary = ucfirst($type) . ' request';
                                    }

                                    if ($summary === '') $summary = '—';

                                    $statusClass = 'status-pending';
                                    if ($status === 'approved')  $statusClass = 'status-approved';
                                    if ($status === 'rejected')  $statusClass = 'status-rejected';
                                    if ($status === 'completed') $statusClass = 'status-completed';

                                    // For marriage certificate: disable download on or before the marriage date
                                    $canDownload = true;
                                    $marriageDateRaw = $details['marriage_date'] ?? null;

                                    if ($type === 'marriage' && !empty($marriageDateRaw)) {
                                        try {
                                            $marriageDate = new DateTimeImmutable($marriageDateRaw);
                                            $today = new DateTimeImmutable('today');
                                            if ($today <= $marriageDate) {
                                                $canDownload = false;
                                            }
                                        } catch (Exception $e) {
                                            $canDownload = true;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>#<?php echo (int)$r['id']; ?></td>
                                    <td><span class="type-pill"><?php echo htmlspecialchars(prettyCertType($type)); ?></span></td>
                                    <td>
                                        <div><?php echo $summary; ?></div>
                                        <?php if (!empty($chips)): ?>
                                            <div class="chip-row">
                                                <?php foreach ($chips as $chip): ?>
                                                    <span class="chip"><?php echo $chip; ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="detail-muted">No extra details</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['notes'])): ?>
                                            <div class="notes"><?php echo nl2br(htmlspecialchars($r['notes'])); ?></div>
                                        <?php else: ?>
                                            <span class="detail-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars(prettyStatus($status)); ?></span></td>
                                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                                    <td><?php echo $r['updated_at'] ? htmlspecialchars($r['updated_at']) : '<span class="detail-muted">—</span>'; ?></td>
                                    <td>
                                        <?php if (!empty($r['output_file'])): ?>
                                            <?php if ($type === 'marriage' && !$canDownload): ?>
                                                <span class="output-link-disabled" title="Download will be available after the marriage date">
                                                    <i class="fas fa-lock"></i> Available after marriage date
                                                </span>
                                            <?php else: ?>
                                                <a class="output-link" href="download_certificate.php?id=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="detail-muted">Not generated yet</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fas fa-inbox"></i>
                        <p>You have not submitted any certificate requests yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
