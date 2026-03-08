<?php
// generate_caste_certificate.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit(); }

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) die($db['error']);
/** @var mysqli $conn */
$conn = $db['conn'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_cast'])) {
    $conn->close();
    http_response_code(405);
    exit('Method not allowed.');
}

/* Fetch Mahal info (issuer) */
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, address, registration_no FROM register WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$mahal_name    = $row['name'] ?? '';
$mahal_address = $row['address'] ?? '';
$mahal_reg     = $row['registration_no'] ?? '';

/* Helpers */
function in_val(string $k): string { return trim($_POST[$k] ?? ''); }
function clean(string $v): string { return htmlspecialchars_decode(strip_tags($v), ENT_QUOTES); }

/* Collect & sanitize inputs */
$applicant_prefix = clean(in_val('applicant_prefix'));
$applicant_name   = clean(in_val('applicant_name'));
$relation_type    = clean(in_val('relation_type'));
$parent_prefix    = clean(in_val('parent_prefix'));
$parent_name      = clean(in_val('parent_name'));
$applicant_dob_raw= in_val('applicant_dob');
$applicant_address= clean(in_val('applicant_address'));
$village_name     = clean(in_val('village_name'));
$taluk_name       = clean(in_val('taluk_name'));
$district_name    = clean(in_val('district_name'));
$state_name       = clean(in_val('state_name'));
$caste_name       = clean(in_val('caste_name'));
$requested_by     = clean(in_val('requested_by'));
$signed_by        = clean(in_val('signed_by'));
$application_date_raw = in_val('application_date');

/* Optional: member_id/request_id */
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$member_id  = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;

/* Dates */
$tz = new DateTimeZone('Asia/Kolkata');
$issue_date = (new DateTime('now', $tz))->format('d F Y');

$app_date = '';
if (!empty($application_date_raw)) {
    $dt = DateTime::createFromFormat('Y-m-d', $application_date_raw, $tz);
    if ($dt) $app_date = $dt->format('d F Y');
}

$applicant_dob = '';
if (!empty($applicant_dob_raw)) {
    $dt2 = DateTime::createFromFormat('Y-m-d', $applicant_dob_raw, $tz);
    if ($dt2) $applicant_dob = $dt2->format('d F Y');
}

/* Template values */
$templateValues = [
    'application_date'   => $app_date ?: $issue_date,
    'applicant_prefix'   => $applicant_prefix,
    'applicant_name'     => $applicant_name,
    'relation_type'      => $relation_type,
    'parent_prefix'      => $parent_prefix,
    'parent_name'        => $parent_name,
    'applicant_dob'      => $applicant_dob ?: ' ',
    'applicant_address'  => $applicant_address ?: ' ',
    'village_name'       => $village_name ?: ' ',
    'taluk_name'         => $taluk_name ?: ' ',
    'district_name'      => $district_name ?: ' ',
    'state_name'         => $state_name ?: ' ',
    'caste_name'         => $caste_name ?: ' ',
    'requested_by'       => $requested_by ?: ' ',
    'signed_by'          => $signed_by ?: ' ',
    'mahal_name'         => $mahal_name ?: ' ',
    'mahal_address'      => $mahal_address ?: ' ',
    'mahal_reg'          => $mahal_reg ?: ' ',
    'issue_date'         => $issue_date,
    'reg_number'         => $mahal_reg ?: date('Ymd'),
];

/* Ensure cert_requests table */
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

/* Add missing columns */
$cols = ['groom_photo','bride_photo','output_blob'];
foreach ($cols as $col) {
    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE '{$col}'");
    if ($res && $res->num_rows === 0) {
        if ($col === 'groom_photo')
            $conn->query("ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL AFTER details_json");
        if ($col === 'bride_photo')
            $conn->query("ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL AFTER groom_photo");
        if ($col === 'output_blob')
            $conn->query("ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file");
    }
}

/* Build details_json */
$details_arr = [
    'applicant_prefix' => $applicant_prefix,
    'applicant_name'   => $applicant_name,
    'relation_type'    => $relation_type,
    'parent_prefix'    => $parent_prefix,
    'parent_name'      => $parent_name,
    'applicant_dob'    => $applicant_dob_raw,
    'applicant_address'=> $applicant_address,
    'village_name'     => $village_name,
    'taluk_name'       => $taluk_name,
    'district_name'    => $district_name,
    'state_name'       => $state_name,
    'caste_name'       => $caste_name,
    'requested_by'     => $requested_by,
    'signed_by'        => $signed_by,
    'application_date' => $application_date_raw,
];

/* Insert request row if needed */
if ($request_id <= 0) {
    $sessionMemberId = 0;
    if (!empty($_SESSION['member'])) {
        $memberSess = $_SESSION['member'];
        $sessionMemberId = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
            ? (int)$memberSess['parent_member_id']
            : (int)$memberSess['member_id'];
    }
    $use_member_id = $member_id > 0 ? $member_id : $sessionMemberId;

    $details_json_insert = $conn->real_escape_string(json_encode($details_arr, JSON_UNESCAPED_UNICODE));
    $sqlIns = "INSERT INTO cert_requests (member_id, certificate_type, details_json, status, created_at)
               VALUES (" . (int)$use_member_id . ", 'caste', '" . $details_json_insert . "', 'completed', NOW())";
    if ($conn->query($sqlIns)) {
        $request_id = (int)$conn->insert_id;
    }
} else {
    $details_json_update = $conn->real_escape_string(json_encode($details_arr, JSON_UNESCAPED_UNICODE));
    $conn->query("UPDATE cert_requests SET details_json = '{$details_json_update}', updated_at = NOW() WHERE id = " . (int)$request_id . " LIMIT 1");
}

/* ----------------- DOCX → PDF generation ----------------- */

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\IOFactory;
use Dompdf\Dompdf;
use Dompdf\Options;

/* Load template */
$tplPath = __DIR__ . '/templates/caste_template_dynamic.docx';
if (!file_exists($tplPath)) {
    $conn->close();
    http_response_code(500);
    exit('Template file not found: ' . htmlspecialchars($tplPath));
}

$tpl = new TemplateProcessor($tplPath);

/* Set values */
foreach ($templateValues as $k => $v) {
    $tpl->setValue($k, $v === '' ? ' ' : $v);
}

/* Save DOCX temp */
$tmpDocx = tempnam(sys_get_temp_dir(), 'caste_') . '.docx';
$tpl->saveAs($tmpDocx);

/* Convert DOCX to HTML */
$phpWord = IOFactory::load($tmpDocx);
$htmlWriter = IOFactory::createWriter($phpWord, 'HTML');

$tmpHtml = tempnam(sys_get_temp_dir(), 'caste_') . '.html';
$htmlWriter->save($tmpHtml);
$htmlContent = file_get_contents($tmpHtml);

/* Convert HTML to PDF */
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($htmlContent, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfOutput = $dompdf->output();

/* Filename */
$filename_safe = 'Caste_Certificate_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $templateValues['reg_number']) . '.pdf';

/* Save PDF to DB */
if ($request_id > 0) {
    try {
        $fileEsc = $conn->real_escape_string($pdfOutput);
        $fnameEsc = $conn->real_escape_string($filename_safe);
        $sqlUp = "UPDATE cert_requests
                  SET output_file = '{$fnameEsc}',
                      output_blob = '{$fileEsc}',
                      status = 'completed',
                      updated_at = NOW()
                  WHERE id = " . (int)$request_id . " LIMIT 1";
        $conn->query($sqlUp);
    } catch (Throwable $e) {
        error_log('generate_caste_certificate: ' . $e->getMessage());
    }
}

/* Close DB */
$conn->close();

/* Output PDF */
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename_safe . '"');
header('Content-Length: ' . strlen($pdfOutput));
echo $pdfOutput;

/* Cleanup */
@unlink($tmpDocx);
@unlink($tmpHtml);

exit;
