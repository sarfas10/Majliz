<?php
// marriage_consent.php
require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) die($db['error']);
/** @var mysqli $conn */
$conn = $db['conn'];

/* --- HANDLE SAVE CHANGES (POST BACK TO SAME FILE) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_changes'])) {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

    if ($request_id <= 0) {
        $conn->close();
        die('Invalid request ID.');
    }

    // Collect form fields into details_json (same keys as used when loading)
    $details = [
        'groom_name'        => $_POST['groom_name']        ?? '',
        'groom_parent'      => $_POST['groom_parent']      ?? '',
        'groom_dob'         => $_POST['groom_dob']         ?? '',
        'groom_address'     => $_POST['groom_address']     ?? '',
        'bride_name'        => $_POST['bride_name']        ?? '',
        'bride_parent'      => $_POST['bride_parent']      ?? '',
        'bride_dob'         => $_POST['bride_dob']         ?? '',
        'bride_address'     => $_POST['bride_address']     ?? '',
        'marriage_date'     => $_POST['marriage_date']     ?? '',
        'marriage_venue'    => $_POST['marriage_venue']    ?? '',
        'cooperating_mahal' => $_POST['cooperating_mahal'] ?? '',
        'reg_number'        => $_POST['reg_number']        ?? '',
        'requested_by'      => $_POST['requested_by']      ?? '',
        'signed_by'         => $_POST['signed_by']         ?? '',
    ];

    $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);

    // Optional photos – only update if new files are uploaded
    $groom_photo_blob = null;
    $bride_photo_blob = null;

    if (!empty($_FILES['groom_photo']['tmp_name'])) {
        $groom_photo_blob = file_get_contents($_FILES['groom_photo']['tmp_name']);
    }
    if (!empty($_FILES['bride_photo']['tmp_name'])) {
        $bride_photo_blob = file_get_contents($_FILES['bride_photo']['tmp_name']);
    }

    // Build dynamic UPDATE query
    $sql   = "UPDATE cert_requests SET details_json = ?, updated_at = NOW()";
    $types = "s";
    $params = [$details_json];

    if ($groom_photo_blob !== null) {
        $sql    .= ", groom_photo = ?";
        $types  .= "s";
        $params[] = $groom_photo_blob;
    }
    if ($bride_photo_blob !== null) {
        $sql    .= ", bride_photo = ?";
        $types  .= "s";
        $params[] = $bride_photo_blob;
    }

    $sql   .= " WHERE id = ? LIMIT 1";
    $types .= "i";
    $params[] = $request_id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        die('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    // Redirect back to avoid form resubmission and show success message
    header("Location: marriage_consent.php?request_id={$request_id}&saved=1");
    exit();
}

/* --- issuer / mahal details from register (admin user) --- */
$user_id = (int)$_SESSION['user_id'];
$sql = "SELECT name, address, registration_no FROM register WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res  = $stmt->get_result();
$row  = $res->fetch_assoc();
$stmt->close();

$mahal_name    = $row['name'] ?? '';
$mahal_address = $row['address'] ?? '';
$mahal_reg     = $row['registration_no'] ?? '';

/* --- fetch consent letter request details from cert_requests --- */
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

$groom_name      = '';
$groom_parent    = '';
$groom_dob       = '';
$groom_address   = '';
$bride_name      = '';
$bride_parent    = '';
$bride_dob       = '';
$bride_address   = '';
$marriage_date   = '';
$marriage_venue  = '';
$cooperating_mahal = '';
$reg_number      = '';
$requested_by    = '';
$signed_by       = '';

$groom_img_preview = '';
$bride_img_preview = '';

if ($request_id > 0) {
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

    $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'groom_photo'");
    if ($resCol && $resCol->num_rows === 0) {
        $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
    }
    $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'bride_photo'");
    if ($resCol && $resCol->num_rows === 0) {
        $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
    }
    $resCol = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'output_blob'");
    if ($resCol && $resCol->num_rows === 0) {
        $conn->query("ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file");
    }

    $sql = "
        SELECT details_json, groom_photo, bride_photo
        FROM cert_requests
        WHERE id = ? AND certificate_type = 'marriage_consent_letter'
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $details = json_decode($r['details_json'] ?? '', true);
            if (is_array($details)) {
                $groom_name        = $details['groom_name']        ?? '';
                $groom_parent      = $details['groom_parent']      ?? '';
                $groom_dob         = $details['groom_dob']         ?? '';
                $groom_address     = $details['groom_address']     ?? '';
                $bride_name        = $details['bride_name']        ?? '';
                $bride_parent      = $details['bride_parent']      ?? '';
                $bride_dob         = $details['bride_dob']         ?? '';
                $bride_address     = $details['bride_address']     ?? '';
                $marriage_date     = $details['marriage_date']     ?? '';
                $marriage_venue    = $details['marriage_venue']    ?? '';
                $cooperating_mahal = $details['cooperating_mahal'] ?? '';
                $reg_number        = $details['reg_number']        ?? '';
                $requested_by      = $details['requested_by']      ?? '';
                $signed_by         = $details['signed_by']         ?? '';
            }

            if (!empty($r['groom_photo'])) {
                $blob = $r['groom_photo'];
                $mime = 'image/jpeg';
                if (strncmp($blob, "\x89PNG", 4) === 0) {
                    $mime = 'image/png';
                }
                $groom_img_preview = 'data:' . $mime . ';base64,' . base64_encode($blob);
            }

            if (!empty($r['bride_photo'])) {
                $blob = $r['bride_photo'];
                $mime = 'image/jpeg';
                if (strncmp($blob, "\x89PNG", 4) === 0) {
                    $mime = 'image/png';
                }
                $bride_img_preview = 'data:' . $mime . ';base64,' . base64_encode($blob);
            }
        }
        $stmt->close();
    } else {
        error_log('marriage_consent: prepare failed: ' . $conn->error);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Marriage Consent Letter — DOCX Generator</title>
  <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box}
    body{margin:0;background:#f9fafb;font-family:'Segoe UI',sans-serif;color:#111827}
    .wrap{max-width:820px;margin:0 auto;padding:40px 20px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px}
    h1{margin:0 0 8px;font-size:24px}
    .sub{color:#6b7280;margin-bottom:20px}
    .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
    .full{grid-column:1 / -1}
    label{font-size:13px;color:#374151;margin:8px 0 6px;font-weight:600}
    input,textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
    input:focus,textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .readonly-box{background:#f3f4f6;border:1px dashed #e5e7eb;border-radius:10px;padding:14px;margin-bottom:18px}
    .muted{color:#6b7280;font-size:13px}
    .btn{padding:12px 16px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:#1d4ed8}
    .img-preview{margin-top:6px;border-radius:8px;max-width:120px;max-height:120px;display:block;border:1px solid #e5e7eb}
    @media (max-width:720px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Marriage Consent Letter — DOCX Generator</h1>
      <div class="sub">Issuer details are auto-fetched from your Mahal profile.</div>

      <?php if (isset($_GET['saved'])): ?>
        <div style="background:#d1fae5;padding:12px;border-radius:8px;color:#065f46;margin-bottom:15px;">
          ✔ Changes saved successfully.
        </div>
      <?php endif; ?>

      <div class="readonly-box">
        <strong><?php echo htmlspecialchars($mahal_name); ?></strong><br>
        <span class="muted"><?php echo nl2br(htmlspecialchars($mahal_address)); ?></span><br>
        <span class="muted">REG. NO: <?php echo htmlspecialchars($mahal_reg); ?></span>
      </div>

      <!-- Default action = this same file (for Save). Generate button overrides via formaction. -->
      <form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="request_id" value="<?php echo (int)$request_id; ?>">

        <div class="grid">
          <div class="full"><h3>Groom Details</h3></div>
          <div>
            <label>Groom Full Name *</label>
            <input type="text" name="groom_name" required
                   value="<?php echo htmlspecialchars($groom_name); ?>" />
          </div>
          <div>
            <label>Groom Parent's Name *</label>
            <input type="text" name="groom_parent" required
                   value="<?php echo htmlspecialchars($groom_parent); ?>" />
          </div>
          <div>
            <label>Groom DOB *</label>
            <input type="date" name="groom_dob" required
                   value="<?php echo htmlspecialchars($groom_dob); ?>" />
          </div>
          <div>
            <label>Groom Photo (JPG/PNG, max 2MB)</label>
            <input type="file" name="groom_photo" accept="image/jpeg,image/png" />
            <?php if ($groom_img_preview): ?>
              <div class="muted">Existing photo (used if you don't upload a new one):</div>
              <img src="<?php echo $groom_img_preview; ?>" alt="Groom photo" class="img-preview">
            <?php else: ?>
              <div class="muted">No photo stored yet.</div>
            <?php endif; ?>
          </div>
          <div class="full">
            <label>Groom Address *</label>
            <textarea name="groom_address" rows="2" required><?php
              echo htmlspecialchars($groom_address);
            ?></textarea>
          </div>

          <div class="full"><h3>Bride Details</h3></div>
          <div>
            <label>Bride Full Name *</label>
            <input type="text" name="bride_name" required
                   value="<?php echo htmlspecialchars($bride_name); ?>" />
          </div>
          <div>
            <label>Bride Parent's Name *</label>
            <input type="text" name="bride_parent" required
                   value="<?php echo htmlspecialchars($bride_parent); ?>" />
          </div>
          <div>
            <label>Bride DOB *</label>
            <input type="date" name="bride_dob" required
                   value="<?php echo htmlspecialchars($bride_dob); ?>" />
          </div>
          <div>
            <label>Bride Photo (JPG/PNG, max 2MB)</label>
            <input type="file" name="bride_photo" accept="image/jpeg,image/png" />
            <?php if ($bride_img_preview): ?>
              <div class="muted">Existing photo (used if you don't upload a new one):</div>
              <img src="<?php echo $bride_img_preview; ?>" alt="Bride photo" class="img-preview">
            <?php else: ?>
              <div class="muted">No photo stored yet.</div>
            <?php endif; ?>
          </div>
          <div class="full">
            <label>Bride Address *</label>
            <textarea name="bride_address" rows="2" required><?php
              echo htmlspecialchars($bride_address);
            ?></textarea>
          </div>

          <div class="full"><h3>Marriage Details</h3></div>
          <div>
            <label>Marriage Date *</label>
            <input type="date" name="marriage_date" required
                   value="<?php echo htmlspecialchars($marriage_date); ?>" />
          </div>
          <div>
            <label>Marriage Venue *</label>
            <input type="text" name="marriage_venue" required
                   value="<?php echo htmlspecialchars($marriage_venue); ?>" />
          </div>
          <div>
            <label>Co-operating Mahal</label>
            <input type="text" name="cooperating_mahal"
                   value="<?php echo htmlspecialchars($cooperating_mahal); ?>" />
          </div>
          <div>
            <label>Certificate Registration No. *</label>
            <input type="text" name="reg_number" required
                   value="<?php echo htmlspecialchars($reg_number); ?>" />
          </div>
          <div>
            <label>Requested By *</label>
            <input type="text" name="requested_by" required
                   value="<?php echo htmlspecialchars($requested_by); ?>" />
          </div>
          <div>
            <label>Signed By (President/Secretary) *</label>
            <input type="text" name="signed_by" required
                   value="<?php echo htmlspecialchars($signed_by); ?>" />
          </div>
        </div>

        <div style="margin-top:20px; display:flex; gap:10px;">
          <!-- Save into DB in this same file -->
          <button class="btn" type="submit" name="save_changes" value="1">
            💾 Save Changes
          </button>

          <!-- Generate DOCX using external generator -->
          <button class="btn" type="submit" name="generate_docx" value="1"
                  formaction="generate_marriage_consent.php">
            📄 Generate Consent Letter
          </button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
