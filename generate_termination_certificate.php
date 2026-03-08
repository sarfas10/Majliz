<?php
// generate_termination_certificate.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) {
    die($db['error']);
}
/** @var mysqli $conn */
$conn = $db['conn'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_pdf'])) {
    $conn->close();
    http_response_code(405);
    exit('Method not allowed.');
}

/* --- request_id --- */
$request_id = (int)($_POST['request_id'] ?? 0);

/* --- ensure columns exist --- */
$appendCols = [
    ['output_blob',  "ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file"],
    ['member_photo', "ALTER TABLE cert_requests ADD member_photo LONGBLOB NULL"]
];
foreach ($appendCols as $col) {
    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE '{$col[0]}'");
    if ($res && $res->num_rows === 0) {
        $conn->query($col[1]);
    }
}

/* --- Fetch Mahal info --- */
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, address, registration_no, phone FROM register WHERE id = ?");
if (!$stmt) {
    $conn->close();
    die("Prepare failed (register): " . $conn->error);
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$org_name    = $row['name'] ?? '';
$org_address = $row['address'] ?? '';
$org_reg     = $row['registration_no'] ?? '';
$org_phone   = $row['phone'] ?? '';

function in_val(string $k): string {
    return trim($_POST[$k] ?? '');
}
function clean(string $v): string {
    return htmlspecialchars_decode(strip_tags($v), ENT_QUOTES);
}

/* --- Read POST fields --- */
$head_name          = clean(in_val('head_name'));
$father_name        = clean(in_val('father_name'));
$dob_raw            = in_val('dob');
$age                = clean(in_val('age'));
$qualification      = clean(in_val('qualification'));
$house_name         = clean(in_val('house_name'));
$city_line          = clean(in_val('city_line'));
$taluk              = clean(in_val('taluk'));
$village_panchayat  = clean(in_val('village_panchayat'));
$phone              = clean(in_val('phone'));
$member_number      = clean(in_val('member_number'));
$destination_mahal  = clean(in_val('destination_mahal'));
$reg_number         = clean(in_val('reg_number'));
$reason_termination = clean(in_val('reason_termination'));
$pending_dues       = clean(in_val('pending_dues'));

/* --- Address string --- */
$address_parts = [];
foreach ([$house_name, $city_line, $taluk, $village_panchayat] as $p) {
    $p = trim($p);
    if ($p !== '') {
        $address_parts[] = $p;
    }
}
$full_address = implode(', ', $address_parts);

/* --- helper: convert member_photo blob to temp file --- */
function member_blob_to_temp(?string $blob, string $prefix = 'member_'): ?string {
    if ($blob === null || $blob === '') return null;

    $bin = $blob;
    $ext = (strncmp($bin, "\x89PNG", 4) === 0) ? '.png' : '.jpg';
    $tmp = tempnam(sys_get_temp_dir(), $prefix) . $ext;
    file_put_contents($tmp, $bin);
    return $tmp;
}

/* --- Fetch family_members & member_photo from DB (for this request) --- */
$family_members    = [];
$member_img_file   = null;

if ($request_id > 0) {
    $stmt = $conn->prepare("
        SELECT details_json, member_photo
        FROM cert_requests
        WHERE id = ? AND certificate_type = 'termination'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $resDetails = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($resDetails && !empty($resDetails['details_json'])) {
            $det = json_decode($resDetails['details_json'], true);
            if (is_array($det)) {
                $family_members = $det['family_members'] ?? [];

                if ($reason_termination === '' && !empty($det['reason_termination'])) {
                    $reason_termination = (string)$det['reason_termination'];
                }
                if ($pending_dues === '' && !empty($det['pending_dues'])) {
                    $pending_dues = (string)$det['pending_dues'];
                }
            }
        }

        if (!empty($resDetails['member_photo'])) {
            $member_img_file = member_blob_to_temp($resDetails['member_photo'], 'member_');
        }
    }
}

/* --- Dates --- */
$tz = new DateTimeZone('Asia/Kolkata');
$issue_date = (new DateTime('now', $tz))->format('d-m-Y');

$dob_disp = '';
if ($dob_raw !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dob_raw, $tz);
    if ($d instanceof DateTime) {
        $dob_disp = $d->format('d-m-Y');
    }
}

$conn->close();

/* --- Create PDF --- */
require __DIR__ . '/vendor/autoload.php';
use Mpdf\Mpdf;

function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/* --- Family members table HTML --- */
$html_family_rows = '';
if (!empty($family_members)) {
    $html_family_rows .= '
    <table width="100%" cellspacing="0" cellpadding="4" style="font-size:9pt;border:1px solid #000;border-collapse:collapse;margin-top:6px;">
      <tr style="background:#f3f4f6;">
        <th style="border:1px solid #000;">#</th>
        <th style="border:1px solid #000;">Name</th>
        <th style="border:1px solid #000;">Relationship</th>
        <th style="border:1px solid #000;">Age</th>
        <th style="border:1px solid #000;">Occupation</th>
      </tr>';
    $i = 1;
    foreach ($family_members as $fm) {
        $html_family_rows .= '
      <tr>
        <td style="border:1px solid #000;text-align:center;">' . esc((string)$i) . '</td>
        <td style="border:1px solid #000;">' . esc($fm['name'] ?? '') . '</td>
        <td style="border:1px solid #000;">' . esc($fm['relationship'] ?? '') . '</td>
        <td style="border:1px solid #000;text-align:center;">' . esc(isset($fm['age']) ? (string)$fm['age'] : '') . '</td>
        <td style="border:1px solid #000;">' . esc($fm['occupation'] ?? '') . '</td>
      </tr>';
        $i++;
    }
    $html_family_rows .= '</table>';
}

/* --- Build member photo tag (CENTERED) --- */
$member_img_tag = '';
if ($member_img_file && is_file($member_img_file)) {
    $member_img_tag = '<img src="' . esc($member_img_file) . '" 
        style="width:120px;height:160px;object-fit:cover;
               border:1px solid #000;border-radius:4px;
               display:block;margin:0 auto;" />';
}

/* --- Main HTML --- */
$html = '
<html>
<body style="font-family:DejaVu Sans, sans-serif; font-size:10pt;">
<div style="border:1px solid #000; padding:6mm;">

  <!-- Header -->
  <div style="text-align:center;">
      <div style="font-size:18pt;font-weight:bold;color:#0ea10e;">' . esc(strtoupper($org_name)) . '</div>
      <div style="font-size:9pt;">' . nl2br(esc($org_address)) . '</div>
      <div style="font-size:9pt;">Phone: ' . esc($org_phone) . '</div>
      <div style="font-size:9pt;">REG. NO: ' . esc($org_reg) . '</div>
  </div>

  <!-- Title -->
  <div style="text-align:center;margin:6px 0;">
    <span style="background:#1d4ed8;color:#fff;padding:4px 18px;border-radius:12px;font-weight:bold;">
      MEMBERSHIP TERMINATION CERTIFICATE
    </span>
  </div>

  <!-- Ref & Date -->
  <table width="100%" cellpadding="4" style="font-size:9pt;">
    <tr>
      <td>Ref No: <strong>' . esc($reg_number) . '</strong></td>
      <td style="text-align:right;">Date: <strong>' . esc($issue_date) . '</strong></td>
    </tr>
  </table>

  <!-- Details + Photo -->
  <table width="100%" cellspacing="0" cellpadding="4" style="border-collapse:collapse;border:1px solid #000;font-size:9pt;">
    <tr>
      <td style="border:1px solid #000;width:25%;">Member Name</td>
      <td style="border:1px solid #000;width:45%;">' . esc($head_name) . '</td>

      <!-- PHOTO CELL -->
      <td rowspan="11" style="border:1px solid #000;width:30%;
           text-align:center;vertical-align:middle;padding:8px 0;">
        ' . ($member_img_tag !== '' 
            ? $member_img_tag 
            : '<div style="font-size:8pt;color:#777;">No Photo</div>') . '
      </td>
    </tr>

    <tr><td style="border:1px solid #000;">Father\'s Name</td><td style="border:1px solid #000;">' . esc($father_name) . '</td></tr>
    <tr><td style="border:1px solid #000;">Qualification</td><td style="border:1px solid #000;">' . esc($qualification) . '</td></tr>
    <tr><td style="border:1px solid #000;">House Name</td><td style="border:1px solid #000;">' . esc($house_name) . '</td></tr>
    <tr><td style="border:1px solid #000;">City, District &amp; Pincode</td><td style="border:1px solid #000;">' . esc($city_line) . '</td></tr>
    <tr><td style="border:1px solid #000;">Taluk</td><td style="border:1px solid #000;">' . esc($taluk) . '</td></tr>
    <tr><td style="border:1px solid #000;">Village, Panchayat</td><td style="border:1px solid #000;">' . esc($village_panchayat) . '</td></tr>
    <tr><td style="border:1px solid #000;">Phone</td><td style="border:1px solid #000;">' . esc($phone) . '</td></tr>
    <tr><td style="border:1px solid #000;">Date of Birth</td><td style="border:1px solid #000;">' . esc($dob_disp) . '</td></tr>
    <tr><td style="border:1px solid #000;">Age</td><td style="border:1px solid #000;">' . esc($age) . '</td></tr>
    <tr><td style="border:1px solid #000;">Member Number</td><td style="border:1px solid #000;">' . esc($member_number) . '</td></tr>
  </table>
';

/* Family members */
if ($html_family_rows !== '') {
    $html .= '
  <div style="margin-top:10px;text-align:center;font-weight:bold;">
    FAMILY MEMBERS (As per Records)
  </div>
  ' . $html_family_rows;
}

/* Office Use */
$html .= '
  <div style="margin-top:14px;">
    <table width="100%" cellspacing="0" cellpadding="6" style="border:1px solid #000;font-size:9pt;border-collapse:collapse;">
      <tr>
        <td colspan="2" style="border:1px solid #000;text-align:center;font-weight:bold;background:#f3f4f6;">
          OFFICE USE ONLY
        </td>
      </tr>
      <tr><td style="border:1px solid #000;width:35%;">Destination Mahal (Transfer To)</td><td style="border:1px solid #000;width:65%;">' . esc($destination_mahal) . '</td></tr>
      <tr><td style="border:1px solid #000;">Reason for Termination</td><td style="border:1px solid #000;">' . esc($reason_termination) . '</td></tr>
      <tr><td style="border:1px solid #000;">Details Regarding Pending Dues</td><td style="border:1px solid #000;">' . esc($pending_dues) . '</td></tr>
    </table>
  </div>

  <div style="margin-top:18px;text-align:right;font-size:10pt;font-weight:bold;">
    President / Secretary
  </div>

</div>
</body>
</html>
';

/* --- mPDF with tighter margins to fit page --- */
$mpdf = new Mpdf([
    'format' => 'A4',
    'margin_left'   => 8,
    'margin_right'  => 8,
    'margin_top'    => 8,
    'margin_bottom' => 8,
    'shrink_tables_to_fit' => 1
]);

$mpdf->WriteHTML($html);

$fileContents = $mpdf->Output('', 'S');
$filename = 'Termination_Certificate_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $reg_number ?: 'cert') . '.pdf';

/* --- Save to DB --- */
$db2 = get_db_connection();
if (!isset($db2['error'])) {
    /** @var mysqli $conn2 */
    $conn2 = $db2['conn'];
    $stmt2 = $conn2->prepare("
        UPDATE cert_requests
        SET output_file = ?, output_blob = ?, status = 'completed', updated_at = NOW()
        WHERE id = ? LIMIT 1
    ");
    if ($stmt2) {
        $null = null;
        $stmt2->bind_param('sbi', $filename, $null, $request_id);
        $stmt2->send_long_data(1, $fileContents);
        $stmt2->execute();
        $stmt2->close();
    }
    $conn2->close();
}

/* --- Output PDF --- */
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($fileContents));
echo $fileContents;

/* cleanup temp file */
if ($member_img_file && is_file($member_img_file)) {
    @unlink($member_img_file);
}

exit;