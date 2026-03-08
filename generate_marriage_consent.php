<?php
// generate_marriage_consent.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_docx'])) {
    $conn->close();
    http_response_code(405);
    exit('Method not allowed.');
}

/* --- request_id (link to cert_requests row) --- */
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

/* Ensure table + columns exist */
$conn->query("
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
");

$res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'groom_photo'");
if ($res && $res->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
}
$res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'bride_photo'");
if ($res && $res->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
}
$res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE 'output_blob'");
if ($res && $res->num_rows === 0) {
    $conn->query("ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file");
}

/* Fetch Mahal info (issuer) */
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, address, registration_no FROM register WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$org_name    = $row['name'] ?? '';
$org_address = $row['address'] ?? '';
$org_reg     = $row['registration_no'] ?? '';

function in_val(string $k): string {
    return trim($_POST[$k] ?? '');
}
function clean(string $v): string {
    return htmlspecialchars_decode(strip_tags($v), ENT_QUOTES);
}

function handle_img(string $key): ?string {
    if (empty($_FILES[$key]['tmp_name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = new finfo(FILEINFO_MIME_TYPE);
    $mime = $f->file($_FILES[$key]['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return null;
    }
    $ext = $mime === 'image/png' ? '.png' : '.jpg';
    $dst = tempnam(sys_get_temp_dir(), 'img_') . $ext;
    if (!move_uploaded_file($_FILES[$key]['tmp_name'], $dst)) {
        return null;
    }
    return $dst;
}

function db_image_to_temp(?string $value, string $prefix = 'img_'): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $uploadsDir = __DIR__ . '/uploads/marriage_photos/';
    $trimmed = trim($value);

    if (is_file($trimmed)) {
        return $trimmed;
    }
    if (is_file($uploadsDir . $trimmed)) {
        return $uploadsDir . $trimmed;
    }

    $data = $trimmed;
    $ext  = '.jpg';

    if (preg_match('#^data:image/(\w+);base64,#i', $data, $m)) {
        $mimeExt = strtolower($m[1]);
        $ext = ($mimeExt === 'png') ? '.png' : '.jpg';
        $data = substr($data, strlen($m[0]));
        $bin = base64_decode($data, true);
        if ($bin === false) {
            return null;
        }
    } else {
        $decoded = base64_decode($data, true);
        if ($decoded !== false && $decoded !== '') {
            $bin = $decoded;
        } else {
            $bin = $value;
        }

        if (strncmp($bin, "\x89PNG", 4) === 0) {
            $ext = '.png';
        } else {
            $ext = '.jpg';
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), $prefix) . $ext;
    if (file_put_contents($tmp, $bin) === false) {
        return null;
    }
    return $tmp;
}

/**
 * Convert image path to base64 data URI for embedding in HTML.
 */
function img_to_data_uri(?string $path): ?string {
    if (!$path || !is_file($path)) {
        return null;
    }
    $mime = mime_content_type($path);
    if ($mime === false) {
        $mime = 'image/jpeg';
    }
    $data = file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $b64 = base64_encode($data);
    return "data:{$mime};base64,{$b64}";
}

/* -------- Read form fields -------- */

$groom_name    = clean(in_val('groom_name'));
$groom_parent  = clean(in_val('groom_parent'));
$groom_dob     = clean(in_val('groom_dob'));
$groom_address = clean(in_val('groom_address'));

$bride_name    = clean(in_val('bride_name'));
$bride_parent  = clean(in_val('bride_parent'));
$bride_dob     = clean(in_val('bride_dob'));
$bride_address = clean(in_val('bride_address'));

$requested_by      = clean(in_val('requested_by'));
$reg_number        = clean(in_val('reg_number'));
$signed_by         = clean(in_val('signed_by'));
$marriage_venue    = clean(in_val('marriage_venue'));
$cooperating_mahal = clean(in_val('cooperating_mahal'));

/* -------- Dates -------- */

$tz = new DateTimeZone('Asia/Kolkata');
$issue_date = (new DateTime('now', $tz))->format('d F Y');

$marriage_date_raw = in_val('marriage_date');
$md = DateTime::createFromFormat('Y-m-d', $marriage_date_raw, $tz) ?: new DateTime('now', $tz);
$marriage_date = $md->format('d F Y');

$groom_dob_fmt = $groom_dob ? date('d F Y', strtotime($groom_dob)) : '';
$bride_dob_fmt = $bride_dob ? date('d F Y', strtotime($bride_dob)) : '';

/* -------- Images -------- */

$groom_img = handle_img('groom_photo');
$bride_img = handle_img('bride_photo');

if ($request_id > 0 && (!$groom_img || !$bride_img)) {
    $stmt = $conn->prepare("
        SELECT groom_photo, bride_photo
        FROM cert_requests
        WHERE id = ? AND certificate_type = 'marriage_consent_letter'
        LIMIT 1
    ");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $photoRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$groom_img && !empty($photoRow['groom_photo'])) {
        $groom_img = db_image_to_temp($photoRow['groom_photo'], 'groom_');
    }
    if (!$bride_img && !empty($photoRow['bride_photo'])) {
        $bride_img = db_image_to_temp($photoRow['bride_photo'], 'bride_');
    }
}

/* Convert to data URIs for HTML */
$groom_img_data = img_to_data_uri($groom_img);
$bride_img_data = img_to_data_uri($bride_img);

$conn->close();

/* -------- Build HTML and generate PDF (Dompdf) -------- */

require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

/* HTML for certificate */
$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Marriage Consent Letter</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 12pt;
            margin: 60px 60px 40px 60px;
            line-height: 1.5;
        }
        .center { text-align: center; }
        .org-name {
            font-size: 16pt;
            font-weight: bold;
        }
        .org-address {
            font-size: 11pt;
        }
        .reg-no {
            font-weight: bold;
            margin-top: 4px;
        }
        .title {
            font-size: 14pt;
            font-weight: bold;
            text-decoration: underline;
            margin-top: 30px;
            margin-bottom: 30px;
        }
        p { text-align: justify; margin: 0 0 12px 0; }
        .photos {
            margin-top: 40px;
            text-align: center;
        }
        .photos-table {
            margin: 0 auto;
        }
        .photos-table td {
            padding: 0 15px;
            text-align: center;
        }
        .photos-table img {
            width: 120px;
            height: 150px;
            object-fit: cover;
        }
        .name-label {
            margin-top: 8px;
            font-size: 11pt;
        }
        .dob-label {
            margin-top: 4px;
            font-size: 10pt;
        }
        .footer {
            margin-top: 40px;
            font-size: 11pt;
        }
        .footer-row {
            width: 100%;
        }
        .footer-left {
            float: left;
        }
        .footer-right {
            float: right;
            text-align: right;
        }
        .clear {
            clear: both;
        }
        .bold { font-weight: bold; }
    </style>
</head>
<body>
    <div class="center">
        <div class="org-name">{$org_name}</div>
        <div class="org-address">{$org_address}</div>
        <div class="reg-no">REG. NO: {$org_reg}</div>
        <div class="title">MARRIAGE CONSENT LETTER</div>
    </div>

    <p>
        This is to state that I hereby give my full consent for the marriage of
        <span class="bold">{$groom_name}</span>, S/o / D/o
        <span class="bold">{$groom_parent}</span>, {$groom_address}, to be solemnized on
        <span class="bold">{$marriage_date}</span> with
        <span class="bold">{$bride_name}</span>, S/o / D/o
        <span class="bold">{$bride_parent}</span>, {$bride_address}, at
        <span class="bold">{$marriage_venue}</span> by the co-operation of
        <span class="bold">{$cooperating_mahal}</span>.
    </p>

    <p>
        This consent is issued as per details available in records and on the request of
        <span class="bold">{$requested_by}</span>.
    </p>

    <div class="photos">
        <table class="photos-table">
            <tr>
                <td>
HTML;

if ($groom_img_data) {
    $html .= '<img src="' . $groom_img_data . '" alt="Groom Photo">';
} else {
    $html .= '&nbsp;';
}

$html .= <<<HTML
                </td>
                <td>
HTML;

if ($bride_img_data) {
    $html .= '<img src="' . $bride_img_data . '" alt="Bride Photo">';
} else {
    $html .= '&nbsp;';
}

$html .= <<<HTML
                </td>
            </tr>
            <tr>
                <td class="name-label">{$groom_name}</td>
                <td class="name-label">{$bride_name}</td>
            </tr>
            <tr>
                <td class="dob-label">{$groom_dob_fmt}</td>
                <td class="dob-label">{$bride_dob_fmt}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <div class="footer-row">
            <div class="footer-left">
                Date: {$issue_date}<br>
                Ref: Reg. No. {$reg_number}
            </div>
            <div class="footer-right">
                President/Secretary<br>
                {$signed_by}
            </div>
            <div class="clear"></div>
        </div>
    </div>
</body>
</html>
HTML;

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$fileContents = $dompdf->output();
$filename = 'Marriage_Consent_Letter_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $reg_number ?: 'consent') . '.pdf';

/* -------- Re-open DB to store output_file + output_blob -------- */
$db2 = get_db_connection();
if (!isset($db2['error'])) {
    /** @var mysqli $conn2 */
    $conn2 = $db2['conn'];

    $resCol = $conn2->query("SHOW COLUMNS FROM cert_requests LIKE 'output_blob'");
    if ($resCol && $resCol->num_rows === 0) {
        $conn2->query("ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file");
    }

    if ($request_id > 0) {
        try {
            $updateSql = "UPDATE cert_requests 
                          SET output_file = ?, output_blob = ?, status = 'completed', updated_at = NOW() 
                          WHERE id = ? LIMIT 1";
            $stmt = $conn2->prepare($updateSql);
            if ($stmt) {
                $null = null;
                $stmt->bind_param('sbi', $filename, $null, $request_id);
                $stmt->send_long_data(1, $fileContents);
                $stmt->execute();
                $stmt->close();
            } else {
                error_log('generate_marriage_consent: prepare update failed: ' . $conn2->error);
            }
        } catch (Throwable $e) {
            error_log('generate_marriage_consent: failed to save pdf to DB: ' . $e->getMessage());
        }
    }

    $conn2->close();
}

/* -------- Send PDF to browser -------- */

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (int)strlen($fileContents));
echo $fileContents;

if ($groom_img && is_file($groom_img)) @unlink($groom_img);
if ($bride_img && is_file($bride_img)) @unlink($bride_img);

exit;
