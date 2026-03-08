<?php
// generate_marriage_certificate.php
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

/* --- request_id (for DB photo retrieval) --- */
$request_id = (int)($_POST['request_id'] ?? 0);

/* --- ensure columns exist --- */
$appendCols = [
    ['groom_photo', "ALTER TABLE cert_requests ADD groom_photo LONGBLOB NULL"],
    ['bride_photo', "ALTER TABLE cert_requests ADD bride_photo LONGBLOB NULL"],
    ['output_blob', "ALTER TABLE cert_requests ADD output_blob LONGBLOB NULL AFTER output_file"],
];
foreach ($appendCols as $col) {
    $res = $conn->query("SHOW COLUMNS FROM cert_requests LIKE '{$col[0]}'");
    if ($res && $res->num_rows === 0) $conn->query($col[1]);
}

/* --- Fetch Mahal info --- */
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, address, registration_no FROM register WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$org_name = $row['name'] ?? '';
$org_address = $row['address'] ?? '';
$org_reg = $row['registration_no'] ?? '';

function in_val(string $k): string { return trim($_POST[$k] ?? ''); }
function clean(string $v): string { return htmlspecialchars_decode(strip_tags($v), ENT_QUOTES); }

/* --- Handle uploaded image --- */
function handle_img(string $key): ?string {
    if (empty($_FILES[$key]['tmp_name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$key]['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png'])) return null;
    $ext = $mime === 'image/png' ? '.png' : '.jpg';
    $dst = tempnam(sys_get_temp_dir(), 'img_') . $ext;
    move_uploaded_file($_FILES[$key]['tmp_name'], $dst);
    return $dst;
}

/* --- Convert DB stored image to temp file --- */
function db_image_to_temp(?string $value, string $prefix='img_'): ?string {
    if (!$value) return null;

    $uploads = __DIR__.'/uploads/marriage_photos/';
    if (is_file($value)) return $value;
    if (is_file($uploads.$value)) return $uploads.$value;

    $data = trim($value);
    if (preg_match('#^data:image/(\w+);base64,#i',$data,$m)) {
        $ext = strtolower($m[1])==='png'?'.png':'.jpg';
        $bin = base64_decode(substr($data, strlen($m[0])));
    } else {
        $bin = base64_decode($data,true);
        if ($bin === false) $bin = $value;
        $ext = (strncmp($bin,"\x89PNG",4)===0)?'.png':'.jpg';
    }

    $tmp = tempnam(sys_get_temp_dir(),$prefix).$ext;
    file_put_contents($tmp,$bin);
    return $tmp;
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
$issue_date = (new DateTime('now',$tz))->format('d F Y');

$md = DateTime::createFromFormat('Y-m-d', in_val('marriage_date'), $tz);
$marriage_date = $md ? $md->format('d F Y') : date('d F Y');

/* --- Images --- */
$groom_img = handle_img('groom_photo');
$bride_img = handle_img('bride_photo');

if ($request_id > 0 && (!$groom_img || !$bride_img)) {
    $stmt = $conn->prepare("SELECT groom_photo, bride_photo FROM cert_requests WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$request_id);
    $stmt->execute();
    $photo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$groom_img && $photo['groom_photo']) $groom_img = db_image_to_temp($photo['groom_photo'],'groom_');
    if (!$bride_img && $photo['bride_photo']) $bride_img = db_image_to_temp($photo['bride_photo'],'bride_');
}
$conn->close();

/* --- Create PDF using mPDF --- */
require __DIR__.'/vendor/autoload.php';
use Mpdf\Mpdf;

function esc($v){ return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }

$groom_dob_h = $groom_dob ? date('d F Y', strtotime($groom_dob)) : '';
$bride_dob_h = $bride_dob ? date('d F Y', strtotime($bride_dob)) : '';

$groom_img_tag = ($groom_img && is_file($groom_img)) ? '<img src="'.$groom_img.'" class="photo">' : '';
$bride_img_tag = ($bride_img && is_file($bride_img)) ? '<img src="'.$bride_img.'" class="photo">' : '';

$html = '
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; line-height:1.5; }

.header { text-align:center; }
.org-name { font-size:14pt; font-weight:bold; text-transform:uppercase; }
.org-address,.org-reg { font-size:10pt; }

.title { text-align:center; font-size:15pt; font-weight:bold; text-decoration:underline; margin-top:10px; }

.content { margin:10px 10mm; }

.photos { width:100%; margin-top:15px; }
.photos td { border:none; text-align:center; padding:5px; }
.photo { width:140px; height:180px; object-fit:cover; }

.photo-name { font-weight:bold; margin-top:5px; }
.photo-dob { font-size:9pt; }

.footer-row { display:flex; justify-content:space-between; width:100%; font-size:10pt; margin-top:8px; }

.note { margin-top:15px; font-size:9pt; text-align:justify; }
</style>
</head>

<body>

<div class="header">
    <div class="org-name">'.esc($org_name).'</div>
    <div class="org-address">'.nl2br(esc($org_address)).'</div>
    <div class="org-reg">Reg. No: '.esc($org_reg).'</div>
</div>

<div class="title">MARRIAGE CERTIFICATE</div>

<!-- New Date + Ref under Title -->
<div style="margin:10px 10mm; text-align:left; font-size:11pt;">
    <div><strong>Date:</strong> '.esc($issue_date).'</div>
    <div><strong>Ref: Reg. No.</strong> '.esc($reg_number).'</div>
</div>

<div class="content">
    This is to certify that the marriage of <strong>'.esc($groom_name).'</strong>,
    S/o / D/o '.esc($groom_parent).', '.esc($groom_address).',
    solemnized on '.esc($marriage_date).' with <strong>'.esc($bride_name).'</strong>,
    S/o / D/o '.esc($bride_parent).', '.esc($bride_address).',
    at '.esc($marriage_venue).' by the co-operation of '.esc($cooperating_mahal).'.
    
</div>


<table class="photos">
<tr>
    <td>
        '.$groom_img_tag.'
        <div class="photo-name">'.esc($groom_name).'</div>
        <div class="photo-dob">'.esc($groom_dob_h).'</div>
    </td>
    <td>
        '.$bride_img_tag.'
        <div class="photo-name">'.esc($bride_name).'</div>
        <div class="photo-dob">'.esc($bride_dob_h).'</div>
    </td>
</tr>
</table>

<!-- Footer -->
<div style="margin:20px 10mm;">
    <div class="footer-row">
        <div></div>
            <div class="note">
        This certificate is issued as per details available in records and on the request of '.esc($requested_by).'.
    </div>
    <div></div>
        <div style="text-align:right; font-weight:bold;">President / Secretary</div>
    </div>
    <div class="footer-row">
        <div></div>
        <div style="text-align:right;">'.esc($signed_by).'</div>
    </div>


</div>

</body>
</html>
';

$mpdf = new Mpdf(['format'=>'A4']);
$mpdf->WriteHTML($html);

$fileContents = $mpdf->Output('', 'S');
$filename = 'Marriage_Certificate_' . preg_replace('/[^A-Za-z0-9_\-]/','_', $reg_number ?: 'cert') . '.pdf';

/* Save to DB */
$db2 = get_db_connection();
if (!isset($db2['error'])) {
    $conn2 = $db2['conn'];
    $stmt = $conn2->prepare("UPDATE cert_requests SET output_file=?, output_blob=?, status='completed', updated_at=NOW() WHERE id=? LIMIT 1");
    if ($stmt) {
        $null = null;
        $stmt->bind_param('sbi', $filename, $null, $request_id);
        $stmt->send_long_data(1, $fileContents);
        $stmt->execute();
        $stmt->close();
    }
    $conn2->close();
}

/* Output PDF */
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.strlen($fileContents));
echo $fileContents;

if ($groom_img && is_file($groom_img)) unlink($groom_img);
if ($bride_img && is_file($bride_img)) unlink($bride_img);

exit;
