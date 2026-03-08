<?php
// generate_marriage_register_pdf.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error']))
    die($db['error']);
/** @var mysqli $conn */
$conn = $db['conn'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_register'])) {
    $conn->close();
    http_response_code(405);
    exit('Method not allowed.');
}

/* --- request_id --- */
$request_id = (int) ($_POST['request_id'] ?? 0);

/* --- Fetch Mahal info --- */
$user_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, address, registration_no FROM register WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$org_name = $row['name'] ?? '';
$org_address = $row['address'] ?? '';
$org_reg = $row['registration_no'] ?? '';

function in_val(string $k): string
{
    return trim($_POST[$k] ?? '');
}
function clean(string $v): string
{
    return htmlspecialchars_decode(strip_tags($v), ENT_QUOTES);
}

/* --- Read fields --- */
$groom_name = clean(in_val('groom_name'));
$groom_parent = clean(in_val('groom_parent'));
$groom_dob = clean(in_val('groom_dob'));
$groom_address = clean(in_val('groom_address'));

$bride_name = clean(in_val('bride_name'));
$bride_parent = clean(in_val('bride_parent'));
$bride_dob = clean(in_val('bride_dob'));
$bride_address = clean(in_val('bride_address'));

$requested_by = clean(in_val('requested_by'));
$reg_number = clean(in_val('reg_number'));
$signed_by = clean(in_val('signed_by'));
$marriage_venue = clean(in_val('marriage_venue'));
$cooperating_mahal = clean(in_val('cooperating_mahal'));

$tz = new DateTimeZone('Asia/Kolkata');
$issue_date = (new DateTime('now', $tz))->format('d/m/Y');

$md = DateTime::createFromFormat('Y-m-d', in_val('marriage_date'), $tz);
$marriage_date = $md ? $md->format('d/m/Y') : date('d/m/Y');

// Format DOBs
$groom_dob_formatted = $groom_dob ? date('d/m/Y', strtotime($groom_dob)) : '';
$bride_dob_formatted = $bride_dob ? date('d/m/Y', strtotime($bride_dob)) : '';

$conn->close();

/* --- Create PDF using mPDF --- */
require __DIR__ . '/vendor/autoload.php';
use Mpdf\Mpdf;

function esc($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$html = '
<html>
<head>
<meta charset="utf-8">
<style>
body { 
    font-family: DejaVu Sans, sans-serif; 
    font-size: 10pt;  /* Increased from 9.5pt */
    line-height: 1.4; /* Increased from 1.3 */
    margin: 0;
    padding: 13mm;    /* Increased from 12mm */
}

.header { 
    text-align: center; 
    margin-bottom: 12px; /* Increased from 10px */
    border-bottom: 2px solid #000;
    padding-bottom: 10px; /* Increased from 8px */
}

.org-name { 
    font-size: 16pt;  /* Increased from 15pt */
    font-weight: bold; 
    text-transform: uppercase;
    margin-bottom: 5px; /* Increased from 3px */
}

.org-address { 
    font-size: 9.5pt; /* Increased from 8.5pt */
    margin-bottom: 3px; /* Increased from 2px */
    line-height: 1.3; /* Increased from 1.2 */
}

.org-reg { 
    font-size: 9.5pt; /* Increased from 8.5pt */
    font-weight: bold;
}

.title { 
    text-align: center; 
    font-size: 14pt;  /* Increased from 13pt */
    font-weight: bold; 
    text-decoration: underline;
    margin: 15px 0;   /* Increased from 12px 0 */
    letter-spacing: 1px;
}

.content-paragraph {
    margin: 18px 0;   /* Increased from 15px 0 */
    text-align: justify;
    line-height: 1.7; /* Increased from 1.6 */
    font-size: 10pt;  /* Match body font size */
}

.reg-info {
    margin: 15px 0;   /* Increased from 12px 0 */
    font-size: 10pt;  /* Increased from 9.5pt */
}

.witness-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;  /* Increased from 12px */
    margin-bottom: 18px; /* Increased from 15px */
}

.witness-table th,
.witness-table td {
    border: 1px solid #000;
    padding: 7px;      /* Increased from 6px */
    text-align: left;
    font-size: 9pt;    /* Increased from 8.5pt */
    line-height: 1.3;  /* Increased from 1.2 */
    height: 26px;      /* Increased from 24px */
}

.witness-table th {
    background-color: #f0f0f0;
    font-weight: bold;
    text-align: center;
}

.witness-table td {
    height: 26px;      /* Increased from 24px */
}

.footer-section {
    margin-top: 20px;  /* Increased from 15px */
    display: table;
    width: 100%;
    page-break-inside: avoid;
}

.footer-left,
.footer-right {
    display: table-cell;
    width: 50%;
    vertical-align: top;
}

.footer-right {
    text-align: right;
}

.signature-line {
    margin-top: 40px;  /* Increased from 30px */
    border-top: 1px solid #000;
    display: inline-block;
    min-width: 200px;
    text-align: center;
    padding-top: 5px;  /* Increased from 4px */
    font-size: 10pt;   /* Increased from 9pt */
}

.date-line {
    margin-top: 40px;  /* Increased from 30px */
    font-size: 10pt;   /* Increased from 9pt */
}

.section-title {
    font-weight: bold;
    font-size: 11pt;   /* Increased from 10pt */
    margin: 18px 0 10px 0; /* Increased margins */
}

hr {
    border: 0;
    border-top: 1px solid #000;
    margin: 5px 0 10px 0; /* Increased margins */
}

.compact-spacing {
    margin: 8px 0;     /* Increased from 5px 0 */
}

table {
    page-break-inside: avoid;
}

.certificate-text {
    margin: 12px 0;    /* Increased from 10px 0 */
    line-height: 1.6;  /* Increased from 1.5 */
}

/* Additional class for even tighter spacing when needed */
.tighter-spacing {
    margin: 3px 0;
    line-height: 3;
}
</style>
</head>

<body>

<div class="header">
    <div class="org-name">' . esc($org_name) . '</div>
    <div class="org-address">' . nl2br(esc($org_address)) . '</div>
    <div class="org-reg">Registration No: ' . esc($org_reg) . '</div>
</div>

<div class="title">MARRIAGE REGISTER</div>

<div class="reg-info compact-spacing">
    <strong>Registration Number:</strong> ' . esc($reg_number) . '<br>
    <strong>Date:</strong> ' . esc($issue_date) . '
</div>

<div class="certificate-text">
    This is to certify that the marriage between <strong>' . esc($groom_name) . '</strong>, 
    son/daughter of <strong>' . esc($groom_parent) . '</strong>, 
    born on <strong>' . esc($groom_dob_formatted) . '</strong>, 
    residing at ' . esc($groom_address) . ', 
    and <strong>' . esc($bride_name) . '</strong>, 
    son/daughter of <strong>' . esc($bride_parent) . '</strong>, 
    born on <strong>' . esc($bride_dob_formatted) . '</strong>, 
    residing at ' . esc($bride_address) . ', 
    was solemnized on <strong>' . esc($marriage_date) . '</strong> 
    at ' . esc($marriage_venue) . ' 
with the co-operation of ' . esc($cooperating_mahal) . ', subject to the conditions mentioned below.
</div>

<div class="compact-spacing tighter-spacing">
    <strong>(1) Mahr</strong>
    <hr>
</div>

<div class="compact-spacing tighter-spacing">
    <strong>(2) Property Rights</strong>
    <hr>
    These conditions have been fully agreed upon.
</div>

<table class="witness-table">
    <thead>
        <tr>
            <th style="width: 15%;">Role</th>
            <th style="width: 25%;">Name</th>
            <th style="width: 25%;">Signature</th>
            <th style="width: 25%;">Address</th>
            <th style="width: 10%;">Age</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="text-align: center;">Groom</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Bride</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Groom\'s Parent/Guardian</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Bride\'s Parent/Guardian</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Witness 1</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Witness 2</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="text-align: center;">Hattib</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
    </tbody>
</table>

<div class="footer-section">
    <div class="footer-left">
        <div class="date-line">Date: ' . esc($issue_date) . '</div>
    </div>
    <div class="footer-right">
        <div class="signature-line">
            ' . esc($signed_by) . '<br>
            <small>President / Secretary</small>
        </div>
    </div>
</div>

</body>
</html>';

// Configure mPDF with adjusted margins
$mpdf = new Mpdf([
    'format' => 'A4',
    'margin_top' => 13,        /* Increased from 12 */
    'margin_bottom' => 13,
    'margin_left' => 13,
    'margin_right' => 13,
    'default_font_size' => 10, /* Increased from 9.5 */
    'default_font' => 'dejavusans',
    'autoPageBreak' => false
]);

$mpdf->WriteHTML($html);

$fileContents = $mpdf->Output('', 'S');
$filename = 'Marriage_Register_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $reg_number ?: 'register') . '.pdf';

while (ob_get_level())
    ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($fileContents));
echo $fileContents;

exit;