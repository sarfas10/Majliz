<?php
// download_certificate.php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';

if (empty($_SESSION['member_login']) || $_SESSION['member_login'] !== true || empty($_SESSION['member'])) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit("Missing id.");
}

$request_id = (int)$_GET['id'];

require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error'])) die($db['error']);
/** @var mysqli $conn */
$conn = $db['conn'];

$sql = "SELECT output_file, output_blob, member_id, status FROM cert_requests WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $request_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$row = $res->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit("Not found.");
}

// Ensure the household member owns this request
$memberSess = $_SESSION['member'];
$household_member_id = (isset($memberSess['member_type']) && $memberSess['member_type'] === 'family' && !empty($memberSess['parent_member_id']))
    ? (int)$memberSess['parent_member_id']
    : (int)$memberSess['member_id'];

if ((int)$row['member_id'] !== $household_member_id) {
    http_response_code(403);
    exit("Not allowed.");
}

$filename = $row['output_file'] ?: ("certificate_" . $request_id . ".docx");
$data = $row['output_blob'];

if (empty($data)) {
    http_response_code(404);
    exit("File not generated.");
}

/* Stream to browser */
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . (int)strlen($data));
echo $data;
exit;
